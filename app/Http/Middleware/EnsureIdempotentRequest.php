<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\IdempotentRequestInFlightException;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes a write endpoint safe to retry by replaying its first response.
 *
 * The flow is claim-first: the key row is inserted *before* the request runs,
 * so the unique index on (user_id, key) — not a read-then-write check — is what
 * arbitrates concurrent duplicates. The loser of that race never reaches the
 * controller, which is what stops a retried order from being placed twice.
 *
 * A request that fails releases its claim, so a client can safely retry after
 * a validation error or a server fault with the same key.
 */
class EnsureIdempotentRequest
{
    /** Header carrying the client-generated key. */
    private const HEADER = 'Idempotency-Key';

    /** How long a stored response stays replayable. */
    private const RETENTION_HOURS = 24;

    /**
     * Claim the key, run the request, and persist its response for replay.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header(self::HEADER));

        if ($key === '') {
            throw ValidationException::withMessages([
                self::HEADER => ['The '.self::HEADER.' header is required for this request.'],
            ]);
        }

        if (mb_strlen($key) > 255) {
            throw ValidationException::withMessages([
                self::HEADER => ['The '.self::HEADER.' header may not be longer than 255 characters.'],
            ]);
        }

        $userId = $request->user()->id;
        $fingerprint = $this->fingerprint($request);

        try {
            $record = IdempotencyKey::query()->create([
                'user_id' => $userId,
                'key' => $key,
                'method' => $request->method(),
                'path' => $request->path(),
                'request_hash' => $fingerprint,
                'state' => IdempotencyKey::STATE_PROCESSING,
                'expires_at' => now()->addHours(self::RETENTION_HOURS),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request already claimed this key. Whether it is still
            // running or finished, this request must not execute again.
            return $this->resolveExisting($userId, $key, $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->release($record);

            throw $exception;
        }

        if ($response->isSuccessful()) {
            $this->store($record, $response);
        } else {
            // Failed attempts must not pin the key, or a client could never
            // retry after correcting a bad payload.
            $this->release($record);
        }

        $response->headers->set('Idempotent-Replay', 'false');

        return $response;
    }

    /**
     * Decide what a duplicate request receives: a conflict, a retry hint, or the stored response.
     */
    private function resolveExisting(string $userId, string $key, string $fingerprint): Response
    {
        $existing = IdempotencyKey::query()
            ->where('user_id', $userId)
            ->where('key', $key)
            ->first();

        if (! $existing) {
            // The winning request released its claim between our failed insert
            // and this read. Asking the client to retry is the safe answer.
            throw new IdempotentRequestInFlightException;
        }

        if (! hash_equals($existing->request_hash, $fingerprint)) {
            Log::warning('Idempotency key reused with a different payload.', [
                'user_id' => $userId,
                'path' => $existing->path,
            ]);

            throw new IdempotencyConflictException;
        }

        if ($existing->state !== IdempotencyKey::STATE_COMPLETED || $existing->response_payload === null) {
            throw new IdempotentRequestInFlightException;
        }

        return new JsonResponse(
            $existing->response_payload,
            $existing->response_status ?? 200,
            ['Idempotent-Replay' => 'true'],
        );
    }

    /**
     * Persist the response so later retries replay it verbatim.
     */
    private function store(IdempotencyKey $record, Response $response): void
    {
        $payload = json_decode((string) $response->getContent(), true);

        if (! is_array($payload)) {
            // Nothing replayable was produced, so do not pin the key.
            $this->release($record);

            return;
        }

        $record->update([
            'state' => IdempotencyKey::STATE_COMPLETED,
            'response_payload' => $payload,
            'response_status' => $response->getStatusCode(),
        ]);
    }

    /**
     * Drop a claim so the key can be used again.
     */
    private function release(IdempotencyKey $record): void
    {
        $record->delete();
    }

    /**
     * Fingerprint the request so a reused key with a changed payload is detected.
     *
     * The body is canonicalised by sorting object keys only. Array order is
     * preserved and therefore significant: two payloads that list the same
     * line items in a different order are treated as different requests.
     */
    private function fingerprint(Request $request): string
    {
        $payload = $request->all();
        $this->sortKeys($payload);

        return hash('sha256', (string) json_encode([
            'method' => $request->method(),
            'path' => $request->path(),
            'body' => $payload,
        ]));
    }

    /**
     * Recursively sort associative array keys in place.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function sortKeys(array &$payload): void
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }

        foreach ($payload as &$value) {
            if (is_array($value)) {
                $this->sortKeys($value);
            }
        }
    }
}

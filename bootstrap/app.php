<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureIdempotentRequest;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'idempotent' => EnsureIdempotentRequest::class,
        ]);

        // The auth middleware resolves a guest redirect target before the
        // exception handler ever sees the failure, and Laravel's default
        // closure calls route('login'). This application has no such route —
        // it is an API with a single-page console in front of it — so an
        // unauthenticated request from a browser would die with
        // "Route [login] not defined" instead of a 401. Returning null for API
        // routes keeps the failure an AuthenticationException, which renders
        // as the standard 401 envelope; anything else lands on the console,
        // which shows the sign-in panel.
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : '/',
        );

        // Every API route carries the baseline limiter; write-heavy routes add
        // a tighter one on top of it in the route files.
        $middleware->api(append: [
            'throttle:api',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('The given data was invalid.', 422, $exception->errors());
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Unauthenticated.', 401);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('This action is unauthorized.', 403);
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Too many requests.', 429)
                ->withHeaders($exception->getHeaders());
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Resource not found.', 404);
        });

        // Catch-all so a server fault still returns the standard envelope
        // instead of Laravel's default shape, and is always logged with the
        // request context needed to find it again.
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') || $exception instanceof HttpExceptionInterface) {
                return null;
            }

            Log::error('Unhandled API exception.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'method' => $request->method(),
                'path' => $request->path(),
                'user_id' => $request->user()?->id,
            ]);

            return ApiResponse::error(
                config('app.debug') ? $exception->getMessage() : 'An unexpected server error occurred.',
                500,
            );
        });
    })->create();

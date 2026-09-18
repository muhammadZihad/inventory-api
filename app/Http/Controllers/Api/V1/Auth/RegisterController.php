<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\IssuePassportTokenAction;
use App\Data\Auth\RegisterData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * @group Authentication
 *
 * APIs for creating and managing Passport bearer tokens.
 */
class RegisterController extends Controller
{
    /**
     * Register
     *
     * Create a user and issue a Passport bearer token.
     *
     * @unauthenticated
     */
    public function __invoke(RegisterRequest $request, IssuePassportTokenAction $tokens): JsonResponse
    {
        $user = User::query()->create(RegisterData::fromArray($request->validated())->toArray());

        return ApiResponse::created([
            'user' => UserResource::make($user)->resolve(),
            ...$tokens->execute($user, 'registration-token'),
        ], 'Registration successful.');
    }
}

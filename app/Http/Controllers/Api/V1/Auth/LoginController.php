<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\IssuePassportTokenAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/**
 * Handles Passport login token issuance for existing users.
 *
 * @group Authentication
 */
class LoginController extends Controller
{
    /**
     * Login
     *
     * Issue a Passport bearer token for valid credentials.
     *
     * @unauthenticated
     */
    public function __invoke(LoginRequest $request, IssuePassportTokenAction $tokens): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return ApiResponse::error('Invalid credentials.', 422, [
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return ApiResponse::success([
            'user' => UserResource::make($user)->resolve(),
            ...$tokens->execute($user, 'login-token'),
        ], 'Login successful.');
    }
}

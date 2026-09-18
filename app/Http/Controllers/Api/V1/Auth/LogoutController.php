<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles revocation of the current Passport access token.
 *
 * @group Authentication
 */
class LogoutController extends Controller
{
    /**
     * Logout
     *
     * Revoke the current Passport access token.
     *
     * @authenticated
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->revoke();

        return ApiResponse::success(null, 'Logout successful.');
    }
}

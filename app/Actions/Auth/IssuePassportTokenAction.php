<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Issues Passport access tokens for authenticated API users.
 */
class IssuePassportTokenAction
{
    /**
     * Create a Passport bearer token payload for the given user.
     *
     * @return array{access_token: string, token_type: string}
     */
    public function execute(User $user, string $name = 'api-token'): array
    {
        $token = $user->createToken($name);

        return [
            'access_token' => $token->accessToken,
            'token_type' => 'Bearer',
        ];
    }
}

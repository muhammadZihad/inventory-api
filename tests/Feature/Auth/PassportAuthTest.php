<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Tests\TestCase;

class PassportAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_login_and_logout_with_standard_response_shape(): void
    {
        Client::factory()->asPersonalAccessTokenClient()->create(['provider' => 'users']);

        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Muhammad AR Zihad',
            'email' => 'zihad@example.com',
            'password' => 'password-secret',
            'password_confirmation' => 'password-secret',
        ]);

        $registerResponse
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Registration successful.')
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'access_token',
                    'token_type',
                ],
            ]);

        $this->assertSame(26, strlen($registerResponse->json('data.user.id')));

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'zihad@example.com',
            'password' => 'password-secret',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->withToken($loginResponse->json('data.access_token'))
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Logout successful.');
    }

    public function test_validation_errors_use_global_error_response_shape(): void
    {
        $this->postJson('/api/v1/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'message',
                'errors' => ['name', 'email', 'password'],
            ]);
    }
}

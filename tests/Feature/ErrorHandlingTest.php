<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Covers the consistency of the API error envelope across failure modes.
 */
class ErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_return_the_error_envelope(): void
    {
        // Reaches the idempotency middleware only after auth, so a missing
        // token must surface as 401 rather than a server error.
        $this->postJson('/api/v1/orders', ['customer_id' => 'x', 'items' => []])
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthenticated.',
                'errors' => null,
            ]);

        $this->getJson('/api/v1/products')->assertUnauthorized();
    }

    public function test_browser_requests_to_guarded_api_routes_return_401_not_a_redirect(): void
    {
        // A request without Accept: application/json must still fail as a 401.
        // Laravel's default guest redirect would look for a `login` route that
        // this API does not have, turning the 401 into a 500.
        $this->get('/api/v1/products', ['Accept' => 'text/html,application/xhtml+xml'])
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->get('/api/v1/orders')->assertUnauthorized();
    }

    public function test_unknown_routes_return_the_error_envelope(): void
    {
        $this->getJson('/api/v1/nope')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Resource not found.',
                'errors' => null,
            ]);
    }

    public function test_missing_models_return_the_error_envelope(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->getJson('/api/v1/products/01m2swhmj7dps93xge6qjdzjaf')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Resource not found.');
    }

    public function test_validation_failures_return_field_level_errors(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->postJson('/api/v1/categories', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonStructure(['success', 'message', 'errors' => ['name']]);
    }
}

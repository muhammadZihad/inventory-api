<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AuditFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_auditable_model_sets_created_and_updated_fields_from_authenticated_user(): void
    {
        $creator = User::factory()->create();
        Passport::actingAs($creator);

        $category = Category::query()->create([
            'name' => 'Audit Category',
            'slug' => 'audit-category',
            'status' => 'active',
        ]);

        $this->assertNotNull($category->created_at);
        $this->assertNotNull($category->updated_at);
        $this->assertSame($creator->id, $category->created_by);
        $this->assertSame($creator->id, $category->updated_by);

        $updater = User::factory()->create();
        Passport::actingAs($updater);

        $category->update(['name' => 'Updated Audit Category']);

        $category->refresh();

        $this->assertSame($creator->id, $category->created_by);
        $this->assertSame($updater->id, $category->updated_by);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\Blueprint as ProjectBlueprint;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The schema builder must resolve App\Database\Blueprint, which is what makes
 * ulid(), actionAt() and actionBy() available to every migration.
 */
class BlueprintHelpersTest extends TestCase
{
    public function test_the_custom_blueprint_is_used_for_schema_building(): void
    {
        Schema::create('blueprint_probe', function (Blueprint $table): void {
            $table->ulid();
            $table->actionAt();
            $table->actionBy();
        });

        $this->assertTrue(Schema::hasColumn('blueprint_probe', 'id'));
        $this->assertTrue(Schema::hasColumn('blueprint_probe', 'created_at'));
        $this->assertTrue(Schema::hasColumn('blueprint_probe', 'updated_at'));
        $this->assertTrue(Schema::hasColumn('blueprint_probe', 'created_by'));
        $this->assertTrue(Schema::hasColumn('blueprint_probe', 'updated_by'));

        Schema::dropIfExists('blueprint_probe');
    }

    public function test_the_container_resolves_the_project_blueprint(): void
    {
        // Laravel's schema builder resolves Blueprint from the container, so
        // this binding is what puts the project helpers on every migration.
        $blueprint = app(Blueprint::class, [
            'connection' => app('db')->connection(),
            'table' => 'probe',
        ]);

        $this->assertInstanceOf(ProjectBlueprint::class, $blueprint);
    }
}

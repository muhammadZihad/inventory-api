<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlueprintMacrosTest extends TestCase
{
    public function test_custom_blueprint_helpers_are_registered(): void
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
}

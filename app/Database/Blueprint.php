<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\Schema\Blueprint as BaseBlueprint;
use Illuminate\Support\Fluent;

/**
 * Extends Laravel schema blueprints with project-specific reusable columns.
 */
class Blueprint extends BaseBlueprint
{
    /**
     * Add a ULID column and make the default id column the primary key.
     */
    public function ulid($column = 'id', $length = 26): Fluent
    {
        $definition = parent::ulid($column, $length);

        if ($column === 'id') {
            $definition->primary();
        }

        return $definition;
    }

    /**
     * Add created_at and updated_at columns, or a nullable named action date column.
     */
    public function actionAt(?string $action = null)
    {
        if ($action) {
            return $this->dateTime($action.'_at')->nullable();
        }

        $this->dateTime('created_at')->nullable();
        $this->dateTime('updated_at')->nullable();
    }

    /**
     * Add created_by and updated_by actor columns, or a nullable named actor column.
     */
    public function actionBy(?string $action = null)
    {
        if ($action) {
            return $this->char($action.'_by', 26)->nullable()->index();
        }

        $this->char('created_by', 26)->nullable()->index();
        $this->char('updated_by', 26)->nullable()->index();
    }
}

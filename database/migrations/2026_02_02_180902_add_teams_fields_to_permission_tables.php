<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');

        // 1. Update Roles Table
        Schema::table($tableNames['roles'], function (Blueprint $table) use ($columnNames) {
            // Must be nullable so Global Roles (Super Admin) don't need an Org
            $table->uuid($columnNames['team_foreign_key'])->nullable()->after('guard_name');

            // Drop the old unique constraint and add the new scoped one
            $table->dropUnique(['name', 'guard_name']);
            $table->unique([$columnNames['team_foreign_key'], 'name', 'guard_name']);
        });

        // 2. Update model_has_roles
        Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($columnNames) {
            // Change from NOT NULL to nullable()
            $table->uuid($columnNames['team_foreign_key'])->nullable()->after('model_id')->index();
        });

        // 3. Update model_has_permissions
        Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($columnNames) {
            $table->uuid($columnNames['team_foreign_key'])->nullable()->after('model_id')->index();
        });
    }

    public function down(): void
    {
        // Standard rollback logic here...
    }
};

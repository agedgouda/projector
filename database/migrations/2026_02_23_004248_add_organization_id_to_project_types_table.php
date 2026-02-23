<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_types', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();

            $table->dropUnique(['name']);
            $table->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('project_types', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'name']);
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
            $table->unique('name');
        });
    }
};

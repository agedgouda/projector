<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Clean up orphaned Spatie org-scoped roles (keep only global super-admin)
        DB::table('model_has_roles')->whereNotNull('team_id')->delete();

        Schema::table('organization_user', function (Blueprint $table) {
            $table->unique(['user_id', 'organization_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_user', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'organization_id']);
        });
    }
};

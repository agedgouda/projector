<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Usage rows outlive their organization (demo sandboxes are deleted after a day), keeping the
     * organization's membership tier so spend can still be totalled by tier afterwards.
     */
    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
        });

        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->change();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->string('membership_tier')->nullable()->index();
        });

        DB::statement('UPDATE ai_usage_logs SET membership_tier = organizations.membership_tier FROM organizations WHERE organizations.id = ai_usage_logs.organization_id');
    }

    public function down(): void
    {
        DB::table('ai_usage_logs')->whereNull('organization_id')->delete();

        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('membership_tier');
        });

        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable(false)->change();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }
};

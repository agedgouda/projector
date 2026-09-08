<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Two different Projector organizations legitimately installing the app into the same
        // real Slack workspace (e.g. an agency's various client orgs all living in the agency's
        // own Slack team) is a normal case, not a data error — organization_id is already the
        // uniqueness that matters here (see the previous migration and OrganizationSlackController
        // ::callback()'s updateOrCreate(['organization_id' => ...])), so team_id shouldn't also
        // be forced unique. A plain (non-unique) index replaces it, since every Slack webhook
        // controller still looks rows up by team_id and that shouldn't become a full table scan.
        Schema::table('slack_workspaces', function (Blueprint $table) {
            $table->dropUnique('slack_workspaces_team_id_unique');
            $table->index('team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('slack_workspaces', function (Blueprint $table) {
            $table->dropIndex(['team_id']);
            $table->unique('team_id');
        });
    }
};

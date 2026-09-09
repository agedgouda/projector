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
        Schema::create('dropbox_workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->unique('organization_id');
            $table->string('account_id');
            $table->string('account_name');
            $table->text('access_token');
            // Access tokens issued with token_access_type=offline expire, unlike Slack's
            // long-lived bot token — the refresh_token is what actually lasts, used to mint a
            // fresh access_token whenever the stored one has expired.
            $table->text('refresh_token');
            // When the current access_token expires — checked before every API call
            // (DropboxApiClient::ensureFreshToken()) so a token is refreshed proactively rather
            // than discovered stale via a failed request.
            $table->timestamp('access_token_expires_at')->nullable();
            // Dropbox's own opaque paging token for files/list_folder/continue — lets the
            // webhook handler ask for only what's changed since the last notification instead
            // of re-listing the whole account every time. Null until the first successful
            // files/list_folder call establishes one.
            $table->text('cursor')->nullable();
            $table->foreignId('installed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dropbox_workspaces');
    }
};

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
        // A column mapping a human has explicitly confirmed for a project — either by
        // completing a "smart" import's confirm-mapping modal directly, or by resolving a
        // SlackPendingImport through that same modal. ImportSlackFile checks this before
        // auto-importing a file it classified on its own: only a mapping this project has seen
        // and had confirmed before skips the validation queue. mapping_hash (rather than a
        // unique index on the json column itself, which Postgres can't do portably without an
        // expression index) is what actually enforces "confirmed once per project" — see
        // ProjectImportMapping::fingerprint().
        Schema::create('project_import_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('list_type');
            $table->json('mapping');
            $table->string('mapping_hash');
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'list_type', 'mapping_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_import_mappings');
    }
};

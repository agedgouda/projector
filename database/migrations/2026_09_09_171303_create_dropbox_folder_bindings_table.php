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
        Schema::create('dropbox_folder_bindings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->foreignUuid('dropbox_workspace_id')->constrained()->cascadeOnDelete();
            // Dropbox's own stable folder id — preferred over the path for both the unique
            // constraint and API calls, since a path changes if the folder is renamed or moved
            // but the id doesn't.
            $table->string('folder_id');
            $table->string('folder_path');
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->unique(['dropbox_workspace_id', 'folder_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dropbox_folder_bindings');
    }
};

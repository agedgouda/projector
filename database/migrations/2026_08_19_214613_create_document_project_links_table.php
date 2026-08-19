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
        Schema::create('document_project_links', function (Blueprint $table) {
            $table->id();
            // A task's own project_id (on the documents table) stays its one "home" board —
            // this table only ever represents *additional* boards it's also shown on, so a
            // row here always implies project_id != documents.project_id for that document
            // (enforced in DocumentController, not at the DB level, since that condition spans
            // two tables). Deleting the document or the linked project cleans up the link.
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['document_id', 'project_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_project_links');
    }
};

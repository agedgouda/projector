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
        Schema::table('documents', function (Blueprint $table) {
            // Once a document is processed via a user-chosen protocol (not a raw AI template, and
            // not the universal intake step), its whole downstream lineage is locked to that
            // protocol — further processing auto-continues via that protocol's own workflow_steps,
            // no further choice offered, until the protocol defines no further step.
            $table->foreignUuid('locked_project_type_id')->nullable()
                ->constrained('project_types')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locked_project_type_id');
        });
    }
};

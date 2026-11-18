<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Project types ("protocols") and their workflow steps are retired. down() restores the
     * structure only — the dropped rows are not recoverable from this migration.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locked_project_type_id');
        });

        Schema::table('lifecycle_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_type_id');
        });

        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('project_types');
    }

    public function down(): void
    {
        Schema::create('project_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('icon')->nullable();
            $table->json('document_schema')->nullable();
            $table->jsonb('workflow')->nullable();
            $table->foreignUuid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_template')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_type_id')->constrained('project_types')->cascadeOnDelete();
            $table->string('from_key');
            $table->string('to_key');
            $table->foreignId('ai_template_id')->nullable()->constrained('ai_templates')->nullOnDelete();
            $table->boolean('single_output')->default(false);
            $table->unsignedInteger('order');
            $table->timestamps();
        });

        Schema::table('lifecycle_steps', function (Blueprint $table) {
            $table->foreignUuid('project_type_id')->nullable()->constrained('project_types')->cascadeOnDelete();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignUuid('locked_project_type_id')->nullable()->constrained('project_types')->nullOnDelete();
        });
    }
};

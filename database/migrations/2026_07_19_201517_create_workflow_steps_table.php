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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_steps');
    }
};

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
        Schema::create('lifecycle_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_type_id')->constrained('project_types')->cascadeOnDelete();
            $table->unsignedInteger('order');
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('color', 50)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lifecycle_steps');
    }
};

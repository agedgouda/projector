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
        Schema::create('dismissed_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('recording_id');
            $table->timestamps();

            $table->unique(['project_id', 'recording_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dismissed_recordings');
    }
};

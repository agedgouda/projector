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
        Schema::create('comments', function (Blueprint $table) {
            $table->id();

            // The author of the comment
            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->text('body');

            /** * This creates:
             * 1. commentable_id (bigint)
             * 2. commentable_type (string)
             * Plus an index on both for performance.
             */
            $table->morphs('commentable');

            $table->timestamps();

            // Soft deletes are optional, but useful for discussions
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};

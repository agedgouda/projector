<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('content');
            $table->vector('embedding', 768);
            $table->string('type')->default('dna');
            $table->timestamps();
        });

        // Add a high-performance HNSW index for fast semantic search
        DB::statement('CREATE INDEX ON documents USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void {
        Schema::dropIfExists('documents');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->nullable()->nullOnDelete()->constrained('users');
            $table->foreignId('editor_id')->nullable()->nullOnDelete()->constrained('users');
            $table->string('name')->nullable();
            $table->string('type');
            $table->text('content');
            $table->vector('embedding', 768)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_documents');
    }
};

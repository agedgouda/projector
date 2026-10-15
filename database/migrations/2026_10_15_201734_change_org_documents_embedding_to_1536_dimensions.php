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
        // Matches documents.embedding (1536, OpenAI text-embedding-3-small, the default vector
        // driver) so org and project documents share one vector space. Every save into the old
        // 768 column failed with the default driver, so there's nothing worth converting — and
        // pgvector can't resize a vector anyway. Only the column is recreated; rows are kept.
        // Re-queue embeddings afterwards with `php artisan app:reembed-org-documents`.
        Schema::table('org_documents', function (Blueprint $table) {
            $table->dropColumn('embedding');
        });

        Schema::table('org_documents', function (Blueprint $table) {
            $table->vector('embedding', 1536)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('org_documents', function (Blueprint $table) {
            $table->dropColumn('embedding');
        });

        Schema::table('org_documents', function (Blueprint $table) {
            $table->vector('embedding', 768)->nullable();
        });
    }
};

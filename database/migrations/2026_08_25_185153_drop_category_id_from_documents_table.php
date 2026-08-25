<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Carry forward any existing single-category assignment into the new pivot table
        // before the column disappears — category_id → categories is becoming a many-to-many
        // (documents can have multiple tags now), not just a rename.
        $now = now();
        $rows = DB::table('documents')
            ->whereNotNull('category_id')
            ->get(['id', 'category_id'])
            ->map(fn ($doc) => [
                'category_id' => $doc->category_id,
                'document_id' => $doc->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            DB::table('category_document')->insert($rows);
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignUuid('category_id')->nullable()->after('priority')->constrained('categories')->nullOnDelete();
        });
    }
};

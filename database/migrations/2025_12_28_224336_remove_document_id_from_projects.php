<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // 1. Drop the foreign key constraint first
            $table->dropForeign(['document_id']);

            // 2. Drop the column
            $table->dropColumn('document_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Reverse the process: add column then constraint
            $table->foreignUuid('document_id')->nullable()->constrained()->nullOnDelete();
        });
    }
};

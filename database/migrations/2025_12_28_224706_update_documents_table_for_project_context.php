<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignUuid('project_id')
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('type')->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');

            // Restore the 'dna' default if we roll back
            $table->string('type')->default('dna')->change();
        });
    }
};

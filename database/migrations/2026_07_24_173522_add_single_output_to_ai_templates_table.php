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
        Schema::table('ai_templates', function (Blueprint $table) {
            $table->boolean('single_output')->default(false)->after('generation_brief');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_templates', function (Blueprint $table) {
            $table->dropColumn('single_output');
        });
    }
};

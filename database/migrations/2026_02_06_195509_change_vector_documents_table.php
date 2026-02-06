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
    // 1. Clear existing data to avoid 'Not Null' violations during the change
        DB::table('documents')->truncate();

        // 2. Drop the old column and index
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('embedding');
        });

        // 3. Re-create the column with the correct dimensions (1536)
        Schema::table('documents', function (Blueprint $table) {
            // We use 1536 for best-in-class accuracy and driver flexibility
            $table->vector('embedding', 1536)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};

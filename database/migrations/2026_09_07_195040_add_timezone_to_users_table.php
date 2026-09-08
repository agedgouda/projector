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
        // Null means "not set yet" — treated as UTC everywhere this is read, rather than
        // defaulting the column itself to 'UTC', so a digest can tell "explicitly UTC" apart
        // from "never configured" if that distinction is ever useful later.
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone')->nullable()->after('last_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};

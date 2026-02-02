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
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('normalized_name')->unique(); // For the "Google" vs "Google Inc" check
            $table->string('website')->nullable();
            $table->timestamps();
        });
        Schema::create('organization_user', function (Blueprint $table) {
            $table->id(); // Keep pivot ID as auto-increment for performance
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('role')->default('member'); // 'admin', 'member'
            $table->timestamps();
        });

        // database/migrations/xxxx_add_org_id_to_clients_table.php
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->after('id')->nullable()->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};

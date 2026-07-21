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
        Schema::create('document_type_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->boolean('is_task')->default(false);
            $table->unsignedInteger('order');
            $table->timestamps();

            // Global (organization_id null) and each org's own catalog are independently keyed —
            // matches the same global-or-org uniqueness pattern already used by project_types.name.
            $table->unique(['organization_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_type_definitions');
    }
};

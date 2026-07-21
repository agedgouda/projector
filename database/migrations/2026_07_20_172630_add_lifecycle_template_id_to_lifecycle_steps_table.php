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
        Schema::table('lifecycle_steps', function (Blueprint $table) {
            $table->foreignUuid('lifecycle_template_id')->nullable()->after('project_type_id')
                ->constrained('lifecycle_templates')->cascadeOnDelete();

            // project_type_id is being phased out in favor of lifecycle_template_id — make it
            // optional now so new steps don't have to carry a ProjectType at all.
            $table->uuid('project_type_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lifecycle_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lifecycle_template_id');
            $table->uuid('project_type_id')->nullable(false)->change();
        });
    }
};

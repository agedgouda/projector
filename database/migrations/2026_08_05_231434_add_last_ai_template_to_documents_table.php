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
        Schema::table('documents', function (Blueprint $table) {
            // The AI template that most recently generated this document's children — whether via
            // the universal intake step, a locked protocol's workflow step, or a manually chosen
            // Transform. Lets Reprocess re-run "whatever last produced output from this document"
            // even for types with no single, unambiguous next step of their own (see
            // ProjectAiService::process()'s final else-branch and useWorkflow.ts's INTAKE_KEY note).
            $table->foreignId('last_ai_template_id')->nullable()
                ->constrained('ai_templates')->nullOnDelete();
            $table->string('last_output_key')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_ai_template_id');
            $table->dropColumn('last_output_key');
        });
    }
};

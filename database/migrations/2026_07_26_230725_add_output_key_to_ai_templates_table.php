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
        Schema::table('ai_templates', function (Blueprint $table) {
            // The document `type` a template's output gets tagged with, e.g. 'task' — set by
            // the template itself so processing never has to infer it from a protocol's
            // workflow_steps (project_type-driven, from_key/to_key) row.
            $table->string('output_key')->nullable()->after('type');
        });

        // One-time backfill: every ai_template that's only ever been reached through a
        // protocol so far had its output type implied by that protocol's workflow_steps row.
        // Seed output_key from whatever to_key each template has been consistently given, so
        // existing templates keep behaving the same once processing stops consulting
        // workflow_steps at all.
        DB::table('ai_templates')
            ->whereIn('id', function ($query) {
                $query->select('ai_template_id')
                    ->from('workflow_steps')
                    ->whereNotNull('ai_template_id')
                    ->groupBy('ai_template_id')
                    ->havingRaw('count(distinct to_key) = 1');
            })
            ->update([
                'output_key' => DB::table('workflow_steps')
                    ->select('to_key')
                    ->whereColumn('workflow_steps.ai_template_id', 'ai_templates.id')
                    ->limit(1),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_templates', function (Blueprint $table) {
            $table->dropColumn('output_key');
        });
    }
};

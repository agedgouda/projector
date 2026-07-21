<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * For every ProjectType, clone a matching LifecycleTemplate (same name/organization_id),
     * re-parent its lifecycle_steps onto it, and point every project currently using that
     * ProjectType at the new template — preserving every project's current stage exactly, while
     * making lifecycle-stage tracking independent of project_type_id going forward.
     */
    public function up(): void
    {
        $projectTypes = DB::table('project_types')->select('id', 'name', 'organization_id')->get();

        $templateIdByProjectTypeId = [];

        foreach ($projectTypes as $projectType) {
            $templateId = (string) Str::uuid();

            DB::table('lifecycle_templates')->insert([
                'id' => $templateId,
                'name' => $projectType->name,
                'organization_id' => $projectType->organization_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $templateIdByProjectTypeId[$projectType->id] = $templateId;
        }

        foreach ($templateIdByProjectTypeId as $projectTypeId => $templateId) {
            DB::table('lifecycle_steps')
                ->where('project_type_id', $projectTypeId)
                ->update(['lifecycle_template_id' => $templateId]);

            DB::table('projects')
                ->where('project_type_id', $projectTypeId)
                ->update(['lifecycle_template_id' => $templateId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('lifecycle_steps')->update(['lifecycle_template_id' => null]);
        DB::table('projects')->update(['lifecycle_template_id' => null]);
        DB::table('lifecycle_templates')->delete();
    }
};

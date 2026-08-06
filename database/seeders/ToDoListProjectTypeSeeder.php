<?php

namespace Database\Seeders;

use App\Models\ProjectType;
use Illuminate\Database\Seeder;

class ToDoListProjectTypeSeeder extends Seeder
{
    public function run(): void
    {
        if (ProjectType::where('name', 'To Do List')->exists()) {
            $this->command->info('To Do List project type already exists, skipping.');

            return;
        }

        $projectType = ProjectType::create([
            'name' => 'To Do List',
            'icon' => 'Briefcase',
            'organization_id' => null,
            'document_schema' => [
                [
                    'label' => 'Transcription',
                    'key' => 'intake',
                    'is_task' => false,
                ],
                [
                    'label' => 'Meeting Notes',
                    'key' => 'action_items',
                    'is_task' => false,
                ],
            ],
            // Transcriptions always auto-convert to Meeting Notes via the universal intake step
            // (see config('workflow.intake_key')), so a protocol's own workflow only needs to
            // define what happens from Meeting Notes onward.
            'workflow' => [],
        ]);

        $projectType->lifecycleSteps()->createMany([
            ['order' => 1, 'label' => 'Intake', 'description' => null, 'color' => 'indigo'],
            ['order' => 2, 'label' => 'Client Approved', 'description' => null, 'color' => 'blue'],
        ]);

        $this->command->info('To Do List project type created successfully.');
    }
}

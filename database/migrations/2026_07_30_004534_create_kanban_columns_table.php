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
        Schema::create('kanban_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('key'); // frozen forever once created — renaming only ever touches `label`
            $table->string('label');
            $table->string('color', 50)->nullable();
            $table->unsignedInteger('order');
            $table->timestamps();

            $table->unique(['project_id', 'key']);
        });

        // Backfill every pre-existing project with the same 4 default columns its board
        // already implies (todo/in_progress/review/done), so existing task_status/status
        // data lines up automatically with no changes to those values.
        \App\Models\Project::query()->doesntHave('kanbanColumns')->chunkById(100, function ($projects) {
            foreach ($projects as $project) {
                \App\Models\KanbanColumn::seedDefaultsFor($project);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kanban_columns');
    }
};

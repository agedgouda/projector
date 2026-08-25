<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MergeSubprojectsIntoParents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:merge-subprojects {--dry-run : Report what would change without writing anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Move every subproject's documents onto its parent project and tag them with the subproject's name (creating that tag if it doesn't exist). Safe to re-run.";

    /**
     * @var array<int, string>
     */
    private const COLOR_PALETTE = ['slate', 'red', 'amber', 'emerald', 'blue', 'purple', 'pink', 'orange', 'indigo', 'teal'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $subprojects = Project::query()->whereNotNull('parent_id')->with('parent')->get();

        if ($subprojects->isEmpty()) {
            $this->info('No subprojects found.');

            return self::SUCCESS;
        }

        foreach ($subprojects as $subproject) {
            $this->mergeSubproject($subproject, $dryRun);
        }

        return self::SUCCESS;
    }

    private function mergeSubproject(Project $subproject, bool $dryRun): void
    {
        $parent = $subproject->parent;

        if (! $parent) {
            $this->warn("Skipping \"{$subproject->name}\" ({$subproject->id}): parent_id is set but the parent project no longer exists.");

            return;
        }

        $documents = Document::query()->where('project_id', $subproject->id)->get();

        if ($documents->isEmpty()) {
            $this->line("\"{$subproject->name}\": no documents to move.");

            return;
        }

        $tag = $this->findOrCreateTag($parent, $subproject->name, $dryRun);

        if (! $tag) {
            $this->error(
                "Skipping \"{$subproject->name}\": every color in this project family's palette is already ".
                'in use, so a new tag can\'t be created. Move its documents and tag manually.'
            );

            return;
        }

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}\"{$subproject->name}\" -> \"{$parent->name}\": moving {$documents->count()} document(s), tagging with \"{$tag->name}\".");

        if ($dryRun) {
            return;
        }

        DB::transaction(function () use ($documents, $parent, $tag) {
            foreach ($documents as $document) {
                $document->update(['project_id' => $parent->id]);
                $document->categories()->syncWithoutDetaching([$tag->id]);
            }
        });

        Log::info('Merged subproject documents into parent', [
            'subproject_id' => $subproject->id,
            'subproject_name' => $subproject->name,
            'parent_id' => $parent->id,
            'tag_id' => $tag->id,
            'document_count' => $documents->count(),
        ]);
    }

    /**
     * Finds the family's existing tag matching the subproject's name, or creates one with any
     * color from the palette not already used within that family (categories are shared per
     * family, and both name and color are unique per family — see the categories migrations).
     * Returns null only if every palette color is already taken by another category in the family.
     */
    private function findOrCreateTag(Project $parent, string $name, bool $dryRun): ?Category
    {
        $root = $parent->familyRoot();

        $existing = Category::query()->where('project_id', $root->id)->where('name', $name)->first();

        if ($existing) {
            return $existing;
        }

        $usedColors = Category::query()->where('project_id', $root->id)->pluck('color')->all();
        $availableColor = collect(self::COLOR_PALETTE)->first(fn (string $color): bool => ! in_array($color, $usedColors, true));

        if (! $availableColor) {
            return null;
        }

        if ($dryRun) {
            return new Category(['project_id' => $root->id, 'name' => $name, 'color' => $availableColor]);
        }

        return Category::create(['project_id' => $root->id, 'name' => $name, 'color' => $availableColor]);
    }
}

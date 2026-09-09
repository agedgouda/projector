<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A file an import source (Slack, Dropbox, ...) downloaded but couldn't import automatically —
 * either a spreadsheet it couldn't confidently classify, a spreadsheet with a column mapping
 * this project hasn't confirmed before, or a document (which always needs a human to classify,
 * unless a #tag/subfolder forced a type directly — see App\Services\Import\ForcedTypeMatcher) —
 * parked here instead of failing outright or guessing, so a human can resolve it from the Import
 * Wizard landing page.
 *
 * @property string $id
 * @property string $project_id
 * @property string $original_filename
 * @property string $source_type
 * @property string $source
 * @property int|null $uploaded_by_user_id
 * @property string|null $note
 * @property Project $project
 * @property User|null $uploadedBy
 */
class PendingImport extends Model implements HasMedia
{
    use HasUuids, InteractsWithMedia;

    protected $fillable = [
        'project_id',
        'original_filename',
        'source_type',
        'source',
        'uploaded_by_user_id',
        'note',
    ];

    /**
     * Kept on the private 'local' disk (never public) — same reasoning as Document's own
     * 'recording' collection: this is someone's raw data export, only ever read back
     * server-side (via TaskListImportService::analyze() or DocumentFileExtractorService,
     * depending on source_type — see PendingImportController::show()), never linked directly.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->useDisk('local')->singleFile();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}

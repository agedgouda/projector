<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property string $id
 * @property string $project_id
 * @property string $original_filename
 * @property int|null $uploaded_by_user_id
 * @property string|null $note
 * @property Project $project
 * @property User|null $uploadedBy
 */
class SlackPendingImport extends Model implements HasMedia
{
    use HasUuids, InteractsWithMedia;

    protected $fillable = [
        'project_id',
        'original_filename',
        'uploaded_by_user_id',
        'note',
    ];

    /**
     * Kept on the private 'local' disk (never public) — same reasoning as Document's own
     * 'recording' collection: this is someone's raw data export, only ever read back
     * server-side to re-run TaskListImportService::analyze() against, never linked directly.
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

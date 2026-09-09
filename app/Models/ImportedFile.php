<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records that a specific file (identified by a sha256 hash of its bytes) has already been
 * imported into a project, so App\Services\Import\FileImportProcessor can recognize a repeat
 * upload — the same file re-shared in Slack, or re-synced into a bound Dropbox folder — and
 * skip it instead of creating duplicate tasks/events or duplicate review-queue entries.
 *
 * @property string $id
 * @property string $project_id
 * @property string $content_hash
 * @property string $original_filename
 * @property string $source
 * @property Project $project
 */
class ImportedFile extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'content_hash',
        'original_filename',
        'source',
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}

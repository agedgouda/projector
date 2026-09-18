<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One chunk (kind=partial) or the concatenated whole (kind=final) of an in-progress browser
 * meeting-audio recording, uploaded incrementally via our own minimal implementation of the
 * tus resumable-upload protocol's creation+concatenation extensions (see
 * App\Http\Controllers\TusUploadController) — MediaRecorder produces a chunk every ~20s during
 * recording, each becomes its own small partial upload here, and Stop concatenates all of them
 * into a final one that feeds the same intake pipeline RecordingIntakeService already runs for
 * the mobile/API single-shot upload paths.
 *
 * @property array<string, string>|null $metadata
 */
class TusUpload extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'project_id',
        'creator_id',
        'kind',
        'upload_length',
        'upload_offset',
        'storage_path',
        'metadata',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }
}

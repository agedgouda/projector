<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
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
        'document_id',
        'kind',
        'upload_length',
        'upload_offset',
        'storage_path',
        'metadata',
        'completed_at',
        'claimed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'completed_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Where this chunk falls in its recording, from the "chunk-{n}.webm" filename the browser
     * gives each one (see BrowserAudioCapture.vue) — null for anything not named that way,
     * which then just sorts by upload time instead.
     */
    public function chunkIndex(): ?int
    {
        $filename = $this->metadata['filename'] ?? null;

        return is_string($filename) && preg_match('/^chunk-(\d+)\./', $filename, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    /**
     * Chunks in recording order — chunk number first, upload time as the tiebreak.
     *
     * @param  Collection<int, TusUpload>  $uploads
     * @return Collection<int, TusUpload>
     */
    public static function inRecordingOrder(Collection $uploads): Collection
    {
        return $uploads
            ->sortBy([
                fn (TusUpload $a, TusUpload $b): int => ($a->chunkIndex() ?? PHP_INT_MAX) <=> ($b->chunkIndex() ?? PHP_INT_MAX),
                fn (TusUpload $a, TusUpload $b): int => ($a->created_at?->getTimestamp() ?? 0) <=> ($b->created_at?->getTimestamp() ?? 0),
            ])
            ->values();
    }
}

<?php

namespace App\Models;

use App\Services\RecordingIntakeService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property Organization|null $organization
 * @property User|null $creator
 * @property string|null $custom_prompt
 */
class OrgDocument extends Model implements HasMedia
{
    use HasUuids, InteractsWithMedia;

    protected $fillable = [
        'organization_id',
        'creator_id',
        'editor_id',
        'name',
        'type',
        'content',
        'embedding',
        'metadata',
        'processed_at',
        'custom_prompt',
        'processing_triggered_by_user_id',
    ];

    protected $hidden = ['embedding'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'processed_at' => 'datetime',
            'creator_id' => 'integer',
            'editor_id' => 'integer',
            'processing_triggered_by_user_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $doc) {
            if (auth()->check()) {
                $doc->creator_id ??= auth()->id();
                $doc->editor_id ??= auth()->id();
            }
        });

        static::updating(function (self $doc) {
            if (auth()->check()) {
                $doc->editor_id = auth()->id();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    /**
     * Source audio for a Status Meeting imported from a recording that had no provider
     * transcript, pending transcription — private 'local' disk, same as Document's own
     * 'recording' collection.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('recording')
            ->useDisk('local')
            ->singleFile()
            ->acceptsMimeTypes(explode(',', RecordingIntakeService::RECORDING_MIMES));
    }
}

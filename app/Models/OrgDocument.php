<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Organization|null $organization
 * @property User|null $creator
 */
class OrgDocument extends Model
{
    use HasUuids;

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
}

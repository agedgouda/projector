<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $slug
 * @property string|null $date
 */
class BlogEntry extends Model
{
    protected $connection = 'blog';

    protected $table = 'entries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::addGlobalScope('blog', fn (Builder $query) => $query->where('collection', 'blog'));
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'published' => 'boolean',
        ];
    }

    public function getParsedDateAttribute(): ?Carbon
    {
        return $this->date ? Carbon::parse($this->date) : null;
    }

    public function getTitleAttribute(): ?string
    {
        return $this->data['title'] ?? null;
    }

    public function getContentAttribute(): ?string
    {
        return $this->data['content'] ?? null;
    }
}

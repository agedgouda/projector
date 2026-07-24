<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 */
class AiTemplate extends Model
{
    protected $fillable = [
        'name',
        'description',
        'generation_brief',
        'type',
        'organization_id',
        'system_prompt',
        'user_prompt',
        'single_output',
    ];

    protected function casts(): array
    {
        return [
            'single_output' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}

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
        'type',
        'organization_id',
        'system_prompt',
        'user_prompt',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}

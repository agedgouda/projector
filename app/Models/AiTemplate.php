<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'output_key',
        'organization_id',
        'system_prompt',
        'user_prompt',
        'single_output',
        'import_config',
    ];

    protected function casts(): array
    {
        return [
            'single_output' => 'boolean',
            'import_config' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Templates an organization may use: the global ones (no organization) plus its own —
     * never another organization's.
     *
     * @param  Builder<AiTemplate>  $query
     * @return Builder<AiTemplate>
     */
    public function scopeAvailableToOrganization(Builder $query, ?string $organizationId): Builder
    {
        return $query->where(function (Builder $query) use ($organizationId) {
            $query->whereNull('organization_id');

            if ($organizationId !== null) {
                $query->orWhere('organization_id', $organizationId);
            }
        });
    }
}

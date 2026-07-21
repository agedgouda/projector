<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LifecycleTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\LifecycleTemplateFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'organization_id'];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<LifecycleStep, $this>
     */
    public function lifecycleSteps(): HasMany
    {
        return $this->hasMany(LifecycleStep::class)->orderBy('order');
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}

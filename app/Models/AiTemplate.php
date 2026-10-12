<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    /**
     * The types users pick by hand — the Transform picker's workflow transformations and Import
     * Data's saved import transformations. Only these can be turned off per organization; every
     * other type runs automatically (status meetings, classification, extraction, Slack rules).
     *
     * @var list<string>
     */
    public const TOGGLEABLE_TYPES = ['workflow', 'spreadsheet_import', 'text_import'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The organizations that have turned this transformation off (see
     * Organization::disabledAiTemplates()).
     *
     * @return BelongsToMany<Organization, $this>
     */
    public function disabledForOrganizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_disabled_ai_templates')->withTimestamps();
    }

    /**
     * Whether an organization can turn this transformation off. The universal Notes -> Action
     * Items template runs automatically for every intake document, so it never can.
     */
    public function isToggleable(): bool
    {
        return in_array($this->type, self::TOGGLEABLE_TYPES, true)
            && $this->id !== config('workflow.intake_to_action_items_ai_template_id');
    }

    /**
     * Whether this organization can turn this transformation off: one users pick by hand, that
     * the organization can see (a global one or its own).
     */
    public function isToggleableFor(?string $organizationId): bool
    {
        return $organizationId !== null
            && $this->isToggleable()
            && ($this->organization_id === null || $this->organization_id === $organizationId);
    }

    /**
     * Whether an organization has turned this transformation off.
     */
    public function isDisabledFor(?string $organizationId): bool
    {
        return $organizationId !== null
            && self::query()->whereKey($this->id)->enabledForOrganization($organizationId)->doesntExist();
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

    /**
     * Leaves out the transformations an organization has turned off in its Transformation
     * Library — for its manual pickers only. Protocol steps and Reprocess still run a turned-off
     * transformation, so turning one off never silently breaks an existing workflow.
     *
     * @param  Builder<AiTemplate>  $query
     * @return Builder<AiTemplate>
     */
    public function scopeEnabledForOrganization(Builder $query, ?string $organizationId): Builder
    {
        if ($organizationId === null) {
            return $query;
        }

        return $query->whereNotExists(function ($query) use ($organizationId) {
            $query->selectRaw('1')
                ->from('organization_disabled_ai_templates')
                ->whereColumn('organization_disabled_ai_templates.ai_template_id', 'ai_templates.id')
                ->where('organization_disabled_ai_templates.organization_id', $organizationId);
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * @property string|null $organization_id
 * @property string $key
 * @property string $label
 * @property bool $is_task
 * @property int $order
 */
class DocumentTypeDefinition extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentTypeDefinitionFactory> */
    use HasFactory;

    protected $fillable = ['organization_id', 'key', 'label', 'is_task', 'order'];

    protected function casts(): array
    {
        return [
            'is_task' => 'boolean',
        ];
    }

    /**
     * Null organization_id means this type definition is global/shared across all organizations.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The effective document-type catalog for an organization: global (organization_id null)
     * definitions, overridden by that org's own definitions where a key exists in both. This is
     * the single source of truth for document type labels/is_task, independent of any protocol.
     *
     * @return Collection<string, self>
     */
    public static function catalogForOrganization(?string $organizationId): Collection
    {
        return static::query()
            ->where(function ($query) use ($organizationId) {
                $query->whereNull('organization_id');
                if ($organizationId !== null) {
                    $query->orWhere('organization_id', $organizationId);
                }
            })
            ->get()
            // Global rows first, so an org-specific row for the same key overrides it when keyBy
            // collapses duplicates (keyBy keeps the last occurrence for a given key).
            ->sortBy(fn (self $definition) => $definition->organization_id === null ? 0 : 1)
            ->keyBy('key');
    }
}

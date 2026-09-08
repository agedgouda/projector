<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A column mapping a human has explicitly confirmed for a project, so a future Slack-uploaded
 * file with this exact mapping can auto-import instead of going through the validation queue
 * again (see ImportSlackFile::handle() and ImportTransformationController::applySpreadsheet()).
 *
 * @property int $id
 * @property string $project_id
 * @property string $list_type
 * @property array<string, string|null> $mapping
 * @property string $mapping_hash
 * @property int|null $confirmed_by_user_id
 * @property Project $project
 * @property User|null $confirmedBy
 */
class ProjectImportMapping extends Model
{
    /**
     * The full field set both SpreadsheetClassificationService's response schema and
     * TaskListImportService::FIELD_SYNONYMS use — mirrored here (not imported from either,
     * to avoid a fingerprint-affecting coupling to either service's own internals changing)
     * so fingerprint() can normalize a mapping to exactly these keys regardless of which of
     * the two ever-slightly-different shapes a caller happens to pass in: the AI classifier
     * always includes all eight (even as null), but a web request validated by
     * ApplyImportTransformationRequest only carries whichever optional keys were actually
     * submitted. Without normalizing first, the exact same real mapping could fingerprint
     * differently depending on which of those two shapes produced it.
     *
     * @var list<string>
     */
    private const FIELDS = ['name', 'priority', 'task_status', 'due_at', 'assignee', 'start_date', 'description', 'tag'];

    protected $fillable = [
        'project_id',
        'list_type',
        'mapping',
        'mapping_hash',
        'confirmed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
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
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    /**
     * Whether a human has already confirmed this exact (project, list_type, mapping)
     * combination before — key order in $mapping doesn't matter (see fingerprint()).
     *
     * @param  array<string, string|null>  $mapping
     */
    public static function isKnown(Project $project, string $listType, array $mapping): bool
    {
        return self::where('project_id', $project->id)
            ->where('list_type', $listType)
            ->where('mapping_hash', self::fingerprint($mapping))
            ->exists();
    }

    /**
     * Records a mapping as confirmed for a project — safe to call every time a human completes
     * a confirm-mapping step, regardless of whether this exact mapping was already known;
     * updateOrCreate keeps the row's confirmed_by/timestamps current for the most recent
     * confirmation instead of accumulating duplicates.
     *
     * @param  array<string, string|null>  $mapping
     */
    public static function record(Project $project, string $listType, array $mapping, ?User $confirmedBy = null): void
    {
        self::updateOrCreate(
            [
                'project_id' => $project->id,
                'list_type' => $listType,
                'mapping_hash' => self::fingerprint($mapping),
            ],
            [
                'mapping' => $mapping,
                'confirmed_by_user_id' => $confirmedBy?->id,
            ]
        );
    }

    /**
     * A stable fingerprint of a mapping's actual field => header assignments — independent of
     * key order (an LLM's JSON object property order isn't guaranteed identical between two
     * structurally-equal responses) and independent of which optional keys were even present
     * (see the FIELDS docblock above), by normalizing to exactly the known field set first.
     *
     * @param  array<string, string|null>  $mapping
     */
    private static function fingerprint(array $mapping): string
    {
        $normalized = [];
        foreach (self::FIELDS as $field) {
            $normalized[$field] = $mapping[$field] ?? null;
        }

        $json = json_encode($normalized);

        // Can only fail for a resource or NaN/INF float in the input — $normalized's values
        // are always string|null (each pulled straight from $mapping, itself always a plain
        // string|null-valued array per every caller's own array{...} shape).
        return hash('sha256', $json !== false ? $json : '');
    }
}

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
 * @property list<string|null>|null $headers
 * @property string|null $headers_hash
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
        'headers',
        'headers_hash',
        'confirmed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'headers' => 'array',
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
        return self::findKnown($project, $listType, $mapping) !== null;
    }

    /**
     * Same lookup as isKnown(), but returns the row itself (or null) rather than a bare
     * boolean — used wherever a caller also needs the row's id, e.g. to stamp a freshly
     * classified (rather than headers-cache-reused) event pass with which recipe owns it.
     *
     * @param  array<string, string|null>  $mapping
     */
    public static function findKnown(Project $project, string $listType, array $mapping): ?self
    {
        return self::where('project_id', $project->id)
            ->where('list_type', $listType)
            ->where('mapping_hash', self::fingerprint($mapping))
            ->first();
    }

    /**
     * Records a mapping as confirmed for a project — safe to call every time a human completes
     * a confirm-mapping step, regardless of whether this exact mapping was already known;
     * updateOrCreate keeps the row's confirmed_by/timestamps current for the most recent
     * confirmation instead of accumulating duplicates.
     *
     * $headers is optional (defaults to none recorded) only so every existing caller that never
     * had a reason to know about headers-based reuse — mostly tests exercising isKnown()
     * directly — doesn't need updating; the one caller that matters, applySpreadsheet(), always
     * passes the real headers so confirmedPassesForHeaders() can find this row later.
     *
     * Returns the confirmed row itself (not just void) so a caller that needs to tag its own
     * output with which recipe produced it — e.g. applySpreadsheet() stamping an event pass
     * with this row's id for ImportTaskList's drop-and-reload — doesn't need a second lookup.
     *
     * @param  array<string, string|null>  $mapping
     * @param  list<string|null>  $headers
     */
    public static function record(Project $project, string $listType, array $mapping, ?User $confirmedBy = null, array $headers = []): self
    {
        return self::updateOrCreate(
            [
                'project_id' => $project->id,
                'list_type' => $listType,
                'mapping_hash' => self::fingerprint($mapping),
            ],
            [
                'mapping' => $mapping,
                'confirmed_by_user_id' => $confirmedBy?->id,
                'headers' => $headers !== [] ? $headers : null,
                'headers_hash' => $headers !== [] ? self::headersFingerprint($headers) : null,
            ]
        );
    }

    /**
     * Every pass a human has already confirmed for a spreadsheet with this exact header row —
     * checked by FileImportProcessor before it ever asks the AI classifier to propose passes,
     * so a project's recurring, unchanged-shape template (e.g. a recurring calendar export)
     * imports identically every time instead of being at the mercy of the classifier possibly
     * proposing a different set of passes on a re-upload whose actual shape hasn't changed (the
     * AI is not guaranteed to be deterministic call to call — see this migration's own
     * docblock). Every row returned is, by construction, already "known" — it came from this
     * exact table — so the caller doesn't need to separately check isKnown() on the result.
     *
     * @param  list<string|null>  $headers
     * @return list<array{list_type: string, mapping: array<string, string|null>, mapping_id: int}>
     */
    public static function confirmedPassesForHeaders(Project $project, array $headers): array
    {
        return array_values(self::where('project_id', $project->id)
            ->where('headers_hash', self::headersFingerprint($headers))
            ->get()
            ->map(fn (self $row) => ['list_type' => $row->list_type, 'mapping' => $row->mapping, 'mapping_id' => $row->id])
            ->all());
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

    /**
     * Order-sensitive on purpose — headers in a different order is a different template as far
     * as reuse is concerned, even if it happens to contain the same column names, since nothing
     * here re-derives which header is "the same" column beyond exact position + text.
     *
     * @param  list<string|null>  $headers
     */
    private static function headersFingerprint(array $headers): string
    {
        $json = json_encode($headers);

        return hash('sha256', $json !== false ? $json : '');
    }
}

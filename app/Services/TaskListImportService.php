<?php

namespace App\Services;

use App\Models\Category;
use App\Models\KanbanColumn;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TaskListImportService
{
    /**
     * Header text a spreadsheet column can use to map onto each task/event field, checked in
     * order — the first header that matches any synonym wins that field, later matches for the
     * same field are left for the user to map by hand rather than guessed at. Task-only and
     * event-only fields both live in this one list since a single analyze() pass runs before
     * the user picks which list type they're importing (see TaskListImportConfirmModal.vue) —
     * suggesting a field the chosen type doesn't use is harmless, it's just never rendered.
     *
     * @var array<string, list<string>>
     */
    private const FIELD_SYNONYMS = [
        'name' => ['name', 'task', 'task name', 'event', 'event name', 'title', 'summary'],
        'priority' => ['priority'],
        'task_status' => ['status', 'task status', 'state', 'column'],
        'due_at' => ['due date', 'due', 'deadline', 'due at', 'end date', 'end'],
        'assignee' => ['assignee', 'assigned to', 'owner', 'responsible'],
        'start_date' => ['start date', 'start', 'begin date', 'begins', 'from date'],
        'description' => ['description', 'notes', 'details', 'about'],
        'tag' => ['tag', 'tags', 'category', 'categories'],
    ];

    /**
     * @return array{headers: list<string>, rows: list<list<string>>, suggested_mapping: array<string, string|null>}
     */
    public function analyze(UploadedFile $file): array
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        // NOT setReadDataOnly(true): a genuine Excel date cell is stored as a raw numeric
        // serial, not text — toArray()'s $formatData below only renders it as a real date
        // string (e.g. "10/17/2026") by consulting the cell's number-format style, which
        // read-data-only mode skips loading entirely. Without style info every date column
        // would come through as an unparseable serial number and get silently dropped.
        $spreadsheet = $reader->load($file->getRealPath());

        /** @var list<list<mixed>> $sheetRows */
        $sheetRows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $rawHeaders = array_map(fn (mixed $value): string => $this->cellToString($value), $sheetRows[0] ?? []);

        // Excel considers a formatted-but-never-typed-into column part of the sheet's used
        // range (this is what drives getHighestColumn()), so real spreadsheets routinely carry
        // several trailing columns with no header at all. Keeping them would offer a blank,
        // unselectable "column" in every field's mapping dropdown — drop those column indices
        // from the header row and every data row alike so headers/rows stay aligned.
        $keptIndexes = array_keys(array_filter($rawHeaders, fn (string $header): bool => $header !== ''));
        $headers = array_values(array_intersect_key($rawHeaders, array_flip($keptIndexes)));

        $rows = array_map(
            fn (array $row): array => array_values(array_intersect_key(
                array_map(fn (mixed $cell): string => $this->cellToString($cell), $row),
                array_flip($keptIndexes)
            )),
            array_slice($sheetRows, 1)
        );

        // Drop fully-empty trailing rows (common in exported spreadsheets) rather than turning
        // them into blank tasks the user then has to notice and delete.
        $rows = array_values(array_filter(
            $rows,
            fn (array $row): bool => count(array_filter($row, fn (string $cell): bool => $cell !== '')) > 0
        ));

        return [
            'headers' => $headers,
            'rows' => $rows,
            'suggested_mapping' => $this->suggestMapping($headers),
        ];
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string|null>
     */
    private function suggestMapping(array $headers): array
    {
        $mapping = array_fill_keys(array_keys(self::FIELD_SYNONYMS), null);

        foreach ($headers as $header) {
            $normalized = $this->normalize($header);

            foreach (self::FIELD_SYNONYMS as $field => $synonyms) {
                if ($mapping[$field] !== null) {
                    continue;
                }

                if (in_array($normalized, $synonyms, true)) {
                    $mapping[$field] = $header;
                    break;
                }
            }
        }

        return $mapping;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '');
    }

    /**
     * PhpSpreadsheet's toArray() types each cell as mixed — normally a scalar or null, but
     * defensively stringified rather than blindly cast in case a cell ever comes back as
     * something else (e.g. a rich-text object) that doesn't support (string) casting.
     */
    private function cellToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return trim((string) $value);
        }

        return '';
    }

    /**
     * Resolves a raw assignee string (a name or email typed into a spreadsheet cell) against an
     * organization's real users first, then its pending invitations — mirroring the resolution
     * order and "inv:" convention used everywhere else assignees are set (see
     * DocumentController::resolveAssignee()). No match just leaves the task unassigned rather
     * than failing the row; a spreadsheet's assignee text is inherently unreliable.
     *
     * @param  Collection<int|string, User>  $users
     * @param  Collection<int, OrganizationInvitation>  $invitations
     * @return array{assignee_id: int|null, pending_assignee_invitation_id: int|null}
     */
    public function resolveAssignee(?string $raw, Collection $users, Collection $invitations): array
    {
        $unset = ['assignee_id' => null, 'pending_assignee_invitation_id' => null];

        $needle = trim((string) $raw);
        if ($needle === '') {
            return $unset;
        }

        $needle = mb_strtolower($needle);

        $user = $users->first(
            fn (User $user): bool => mb_strtolower($user->email) === $needle || mb_strtolower($user->name) === $needle
        );

        if ($user !== null) {
            return ['assignee_id' => $user->id, 'pending_assignee_invitation_id' => null];
        }

        $invitation = $invitations->first(function (OrganizationInvitation $invitation) use ($needle): bool {
            $name = mb_strtolower(trim("{$invitation->first_name} {$invitation->last_name}"));

            return mb_strtolower($invitation->email) === $needle || $name === $needle;
        });

        if ($invitation !== null) {
            return ['assignee_id' => null, 'pending_assignee_invitation_id' => $invitation->id];
        }

        return $unset;
    }

    /**
     * The same 10-color palette CategoryController::store() picks from — a category's color
     * must be unique within its project family, so at most 10 tags can ever exist per family.
     *
     * @var array<int, string>
     */
    private const COLOR_PALETTE = ['slate', 'red', 'amber', 'emerald', 'blue', 'purple', 'pink', 'orange', 'indigo', 'teal'];

    /**
     * Resolves a raw tag string against the project family's existing tags by exact
     * (case-insensitive) name, creating a new one — under the family root, with the first
     * palette color not already in use — when nothing matches. Unlike the "Notes to
     * Events"/"Create Tasks" AI transformations' own tag_names field (see
     * ProjectAiService::resolveTagIds()), which only ever picks from a project's
     * already-existing tags, a spreadsheet import is explicitly expected to introduce tags
     * the project doesn't have yet. $categories is updated in place with any newly created
     * tag so later rows in the same import reuse it instead of trying to create a duplicate.
     * If every palette color is already taken, the row is just left untagged rather than
     * failing the import — mirrors resolveAssignee()'s no-match-leaves-it-unset behavior.
     *
     * @param  Collection<int, Category>  $categories
     */
    public function findOrCreateTag(?string $raw, Project $familyRoot, Collection $categories): ?Category
    {
        $needle = trim((string) $raw);
        if ($needle === '') {
            return null;
        }

        $lowerNeedle = mb_strtolower($needle);

        $existing = $categories->first(fn (Category $category): bool => mb_strtolower($category->name) === $lowerNeedle);
        if ($existing !== null) {
            return $existing;
        }

        $usedColors = $categories->pluck('color')->all();
        $color = collect(self::COLOR_PALETTE)->first(fn (string $color): bool => ! in_array($color, $usedColors, true));

        if ($color === null) {
            return null;
        }

        try {
            $category = $familyRoot->categories()->create([
                'name' => mb_substr($needle, 0, 100),
                'color' => $color,
            ]);
        } catch (\Throwable) {
            // A genuine race (another request created the same name/color between the
            // $categories snapshot above and this insert) — leave the row untagged rather
            // than failing the whole import over one optional field.
            return null;
        }

        $categories->push($category);

        return $category;
    }

    /**
     * Looks up a mapped column's value for one row — $mapping[$field] holds the header text the
     * user chose (or null if that field wasn't mapped to anything), and headers/rows are kept as
     * parallel arrays rather than associative ones since spreadsheet headers aren't guaranteed
     * unique.
     *
     * @param  list<string>  $row
     * @param  list<string>  $headers
     * @param  array<string, string|null>  $mapping
     */
    public function cellFor(array $row, array $headers, array $mapping, string $field): string
    {
        $header = $mapping[$field] ?? null;
        if ($header === null) {
            return '';
        }

        $index = array_search($header, $headers, true);

        return $index === false ? '' : trim((string) ($row[$index] ?? ''));
    }

    public function normalizePriority(string $raw): string
    {
        $normalized = mb_strtolower(trim($raw));

        return in_array($normalized, ['low', 'medium', 'high'], true) ? $normalized : 'medium';
    }

    /**
     * @param  Collection<int, KanbanColumn>  $columns
     */
    public function normalizeStatus(string $raw, Collection $columns, string $default): string
    {
        $normalized = mb_strtolower(trim($raw));

        if ($normalized === '') {
            return $default;
        }

        $match = $columns->first(
            fn (KanbanColumn $column) => mb_strtolower($column->key) === $normalized || mb_strtolower($column->label) === $normalized
        );

        return $match !== null ? $match->key : $default;
    }

    public function parseDate(string $raw): ?string
    {
        if (trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}

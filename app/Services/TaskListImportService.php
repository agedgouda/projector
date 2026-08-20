<?php

namespace App\Services;

use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TaskListImportService
{
    /**
     * Header text a spreadsheet column can use to map onto each task field, checked in order —
     * the first header that matches any synonym wins that field, later matches for the same
     * field are left for the user to map by hand rather than guessed at.
     *
     * @var array<string, list<string>>
     */
    private const FIELD_SYNONYMS = [
        'name' => ['name', 'task', 'task name', 'title', 'summary'],
        'priority' => ['priority'],
        'task_status' => ['status', 'task status', 'state', 'column'],
        'due_at' => ['due date', 'due', 'deadline', 'due at'],
        'assignee' => ['assignee', 'assigned to', 'owner', 'responsible'],
    ];

    /**
     * @return array{headers: list<string>, rows: list<list<string>>, suggested_mapping: array<string, string|null>}
     */
    public function analyze(UploadedFile $file): array
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file->getRealPath());

        /** @var list<list<mixed>> $sheetRows */
        $sheetRows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $headers = array_map(fn (mixed $value): string => $this->cellToString($value), $sheetRows[0] ?? []);

        $rows = array_map(
            fn (array $row): array => array_map(fn (mixed $cell): string => $this->cellToString($cell), $row),
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
}

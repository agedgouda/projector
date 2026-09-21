<?php

namespace App\Services\Reports;

use App\Contracts\LlmDriver;
use App\Models\AiTemplate;
use App\Models\Category;
use App\Models\KanbanColumn;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\AiUsageLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Turns a plain-English report request ("Jane's high priority tasks due next week, as a PDF")
 * into the same filter set the Reports tab's search form sends, plus the file format wanted.
 *
 * The AI only ever proposes names and dates; everything it proposes is resolved here against the
 * project's own real people, statuses, tags, and sub-projects. A request it can't fully resolve is
 * reported back in `unresolved` instead of being quietly narrowed or dropped — silently ignoring
 * "Janet" would hand back a report that looks filtered but isn't.
 */
class ReportRequestParser
{
    /**
     * Used only if the 'slack_report_request' AiTemplate row is ever missing or blank (e.g. a
     * fresh environment before migrations seed it) — the row, editable by super-admins in the
     * Transformation Library, is the normal source of both prompts.
     */
    private const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
        You turn a person's plain-English request for a task report into structured filters. The
        request comes from a chat message, so it may be terse or informal.

        Rules:
        - Only use values from the lists you are given (people, statuses, tags, projects). Never
          invent one. If the request names something that is not in a list, still output what was
          written, exactly as written, so it can be reported back as not found.
        - Leave a filter null (or an empty list) unless the request actually asks for it.
        - Dates: resolve relative dates ("next week", "this month", "last Friday") against today's
          date, given below, into concrete YYYY-MM-DD values. A range is inclusive on both ends. A
          week runs Monday to Sunday. A whole month or quarter means its first through last day.
          A single day is the same date for both from and to. "Overdue" means to = yesterday,
          combined with every status that is not the done status.
        - date_kind: "done" if the request is about when tasks were completed/finished/closed
          ("done last week", "completed in March"), otherwise "due" (also the default when a date
          is given without saying which).
        - assignees: use the person's exact name from the list. Use "me" when the request says "my"
          or "mine" or "I". Use "Unassigned" for tasks with nobody assigned.
        - statuses: use the status key from the list (the value before the colon).
        - format: "excel", "csv", or "pdf" if the request asks for one, otherwise null.
        PROMPT;

    private const DEFAULT_USER_PROMPT = <<<'PROMPT'
        Today: {{today}}
        People: {{people}}
        Statuses (key: label): {{statuses}}
        Tags: {{tags}}
        Projects: {{projects}}

        Request: {{request}}
        PROMPT;

    public function __construct(
        protected LlmDriver $llmDriver,
        protected AiUsageLogger $usageLogger,
        protected TaskReportBuilder $reports,
    ) {}

    /**
     * @return array{
     *     format: 'xlsx'|'csv'|'pdf'|null,
     *     filters: array<string, mixed>,
     *     summary: array<string, string>,
     *     unresolved: list<string>
     * }
     *
     * @throws \Exception when the AI call fails — the caller must not fall back to an unfiltered
     *                    report, since that would silently ignore what the person asked for.
     */
    public function parse(string $text, Project $project, User $user): array
    {
        $text = trim($text);

        if ($text === '') {
            return ['format' => null, 'filters' => ['mode' => 'due'], 'summary' => [], 'unresolved' => []];
        }

        $project->loadMissing('client.organization.users', 'client.organization.invitations', 'kanbanColumns');
        $organization = $project->client?->organization;

        $people = $this->peopleByName($organization?->users ?? collect(), $organization?->invitations ?? collect());
        $columns = $project->kanbanColumns;
        $categories = $project->familyCategories();
        $projectNames = $this->reports->projectNamesMap($project);

        $interpreted = $this->interpret($text, $user, $people, $columns, $categories, $projectNames, $organization?->id);

        return $this->resolve($interpreted, $user, $people, $columns, $categories, $projectNames);
    }

    /**
     * @param  array<string, array{value: string, name: string}>  $people  keyed by lowercased name
     * @param  Collection<int, KanbanColumn>  $columns
     * @param  Collection<int, Category>  $categories
     * @param  array<string, string>  $projectNames
     * @return array<string, mixed>
     */
    private function interpret(string $text, User $user, array $people, Collection $columns, Collection $categories, array $projectNames, ?string $organizationId): array
    {
        $today = Carbon::now($user->effectiveTimezone());

        [$systemPrompt, $userTemplate] = $this->prompts();

        // One pass (strtr) rather than sequential replaces, so text a person types that happens to
        // contain a placeholder like "{{people}}" is never expanded a second time.
        $userPrompt = strtr($userTemplate, [
            '{{today}}' => $today->format('Y-m-d (l)'),
            '{{people}}' => $people === [] ? 'none' : implode(', ', array_column($people, 'name')),
            '{{statuses}}' => $columns->isEmpty() ? 'none' : $columns->map(fn (KanbanColumn $column) => "{$column->key}: {$column->label}")->implode(', '),
            '{{tags}}' => $categories->isEmpty() ? 'none' : $categories->pluck('name')->implode(', '),
            '{{projects}}' => implode(', ', array_values($projectNames)),
            '{{request}}' => $text,
        ]);

        // An edited template that dropped {{request}} would otherwise silently ignore what the
        // person typed.
        if (! str_contains($userTemplate, '{{request}}')) {
            $userPrompt .= "\n\nRequest: ".$text;
        }

        $result = $this->llmDriver->call($systemPrompt, $userPrompt, $this->schema());

        if (($result['status'] ?? '') !== 'success' || ! is_array($result['content'] ?? null)) {
            Log::warning('ReportRequestParser LLM failure', ['error' => $result['message'] ?? 'unknown']);
            throw new \Exception($result['message'] ?? 'AI could not interpret the report request');
        }

        if (isset($result['driver'], $result['model'])) {
            $this->usageLogger->log(
                driver: $result['driver'],
                model: $result['model'],
                type: 'llm',
                inputTokens: $result['input_tokens'] ?? 0,
                outputTokens: $result['output_tokens'] ?? 0,
                organizationId: $organizationId,
            );
        }

        /** @var array<string, mixed> */
        return $result['content'];
    }

    /**
     * The 'slack_report_request' AiTemplate's system and user prompts, editable by super-admins in
     * the Transformation Library — each falling back to its built-in default independently if
     * that half is missing or blank.
     *
     * @return array{0: string, 1: string}
     */
    private function prompts(): array
    {
        $template = AiTemplate::where('type', 'slack_report_request')->first();

        $system = $template?->system_prompt;
        $user = $template?->user_prompt;

        return [
            is_string($system) && trim($system) !== '' ? $system : self::DEFAULT_SYSTEM_PROMPT,
            is_string($user) && trim($user) !== '' ? $user : self::DEFAULT_USER_PROMPT,
        ];
    }

    /**
     * @param  array<string, mixed>  $interpreted
     * @param  array<string, array{value: string, name: string}>  $people
     * @param  Collection<int, KanbanColumn>  $columns
     * @param  Collection<int, Category>  $categories
     * @param  array<string, string>  $projectNames
     * @return array{format: 'xlsx'|'csv'|'pdf'|null, filters: array<string, mixed>, summary: array<string, string>, unresolved: list<string>}
     */
    private function resolve(array $interpreted, User $user, array $people, Collection $columns, Collection $categories, array $projectNames): array
    {
        $unresolved = [];
        $summary = [];
        $filters = [];

        $mode = ($interpreted['date_kind'] ?? null) === 'done' ? 'done' : 'due';
        $filters['mode'] = $mode;

        $assignees = [];
        $assigneeLabels = [];
        foreach ($this->strings($interpreted['assignees'] ?? null) as $name) {
            $key = mb_strtolower($name);

            if ($key === 'me') {
                $assignees[] = (string) $user->id;
                $assigneeLabels[] = $user->name;
            } elseif ($key === 'unassigned') {
                $assignees[] = 'unassigned';
                $assigneeLabels[] = 'Unassigned';
            } elseif (isset($people[$key])) {
                $assignees[] = $people[$key]['value'];
                $assigneeLabels[] = $people[$key]['name'];
            } else {
                $unresolved[] = "a person named \"{$name}\"";
            }
        }
        if ($assignees !== []) {
            $filters['assignee'] = $assignees;
            $summary['assignee'] = 'Assignee: '.implode(', ', $assigneeLabels);
        }

        $statusKeys = [];
        $statusLabels = [];
        foreach ($this->strings($interpreted['statuses'] ?? null) as $value) {
            $column = $columns->first(fn (KanbanColumn $column) => mb_strtolower($column->key) === mb_strtolower($value)
                || mb_strtolower($column->label) === mb_strtolower($value));

            if ($column === null) {
                $unresolved[] = "a status \"{$value}\"";

                continue;
            }

            $statusKeys[] = $column->key;
            $statusLabels[] = $column->label;
        }
        if ($statusKeys !== []) {
            $filters['task_status'] = $statusKeys;
            $summary['status'] = 'Status: '.implode(', ', $statusLabels);
        }

        $priorities = array_values(array_intersect($this->strings($interpreted['priorities'] ?? null), ['low', 'medium', 'high']));
        if ($priorities !== []) {
            $filters['priority'] = $priorities;
            $summary['priority'] = 'Priority: '.implode(', ', array_map('ucfirst', $priorities));
        }

        $categoryIds = [];
        $tagLabels = [];
        foreach ($this->strings($interpreted['tags'] ?? null) as $value) {
            if (mb_strtolower($value) === 'none') {
                $categoryIds[] = 'none';
                $tagLabels[] = 'No tag';

                continue;
            }

            $category = $categories->first(fn (Category $category) => mb_strtolower((string) $category->name) === mb_strtolower($value));

            if ($category === null) {
                $unresolved[] = "a tag \"{$value}\"";

                continue;
            }

            $categoryIds[] = (string) $category->id;
            $tagLabels[] = (string) $category->name;
        }
        if ($categoryIds !== []) {
            $filters['category_id'] = $categoryIds;
            $summary['tag'] = 'Tag: '.implode(', ', $tagLabels);
        }

        $projectIds = [];
        $projectLabels = [];
        foreach ($this->strings($interpreted['projects'] ?? null) as $value) {
            $id = array_search(mb_strtolower($value), array_map('mb_strtolower', $projectNames), true);

            if ($id === false) {
                $unresolved[] = "a project \"{$value}\"";

                continue;
            }

            $projectIds[] = (string) $id;
            $projectLabels[] = $projectNames[$id];
        }
        if ($projectIds !== []) {
            $filters['project_id'] = $projectIds;
            $summary['project'] = 'Project: '.implode(', ', $projectLabels);
        }

        $from = $this->date($interpreted['date_from'] ?? null);
        $to = $this->date($interpreted['date_to'] ?? null);
        foreach (['date_from' => $from, 'date_to' => $to] as $field => $parsed) {
            $raw = $interpreted[$field] ?? null;
            if (is_string($raw) && $raw !== '' && $parsed === null) {
                $unresolved[] = "the date \"{$raw}\"";
            }
        }
        if ($from !== null) {
            $filters['due_from'] = $from->toDateString();
        }
        if ($to !== null) {
            $filters['due_to'] = $to->toDateString();
        }

        $dateLabel = $mode === 'done' ? 'Done' : 'Due';
        if ($from !== null && $to !== null) {
            $summary['dates'] = $from->equalTo($to)
                ? "{$dateLabel} on {$from->format('m/d/Y')}"
                : "{$dateLabel} {$from->format('m/d/Y')} – {$to->format('m/d/Y')}";
        } elseif ($from !== null) {
            $summary['dates'] = "{$dateLabel} on or after {$from->format('m/d/Y')}";
        } elseif ($to !== null) {
            $summary['dates'] = "{$dateLabel} on or before {$to->format('m/d/Y')}";
        } elseif ($mode === 'done') {
            $summary['dates'] = 'Completed tasks';
        }

        // Null when the request names no format — the default differs by report kind (Excel for a
        // task report, PDF for an event calendar), so it's the caller's to choose.
        $format = match ($interpreted['format'] ?? null) {
            'excel' => 'xlsx',
            'csv' => 'csv',
            'pdf' => 'pdf',
            default => null,
        };

        return ['format' => $format, 'filters' => $filters, 'summary' => $summary, 'unresolved' => $unresolved];
    }

    /**
     * Every real person a report can be filtered to, keyed by lowercased full name — users by
     * their id, and pending invitees by the `inv:{id}` form the Reports tab's own assignee filter
     * uses.
     *
     * @param  Collection<int, User>  $users
     * @param  Collection<int, \App\Models\OrganizationInvitation>  $invitations
     * @return array<string, array{value: string, name: string}>
     */
    private function peopleByName(Collection $users, Collection $invitations): array
    {
        $people = [];

        foreach ($invitations as $invitation) {
            $name = trim("{$invitation->first_name} {$invitation->last_name}");
            if ($name !== '') {
                $people[mb_strtolower($name)] = ['value' => 'inv:'.$invitation->id, 'name' => $name];
            }
        }

        // Users last, so a real account wins over a stale pending invitation with the same name.
        foreach ($users as $person) {
            $name = trim((string) $person->name);
            if ($name !== '') {
                $people[mb_strtolower($name)] = ['value' => (string) $person->id, 'name' => $name];
            }
        }

        return $people;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($item) => is_string($item) ? trim($item) : '', $value), fn (string $item) => $item !== ''));
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        // createFromFormat rolls an impossible date (2026-02-30) forward instead of failing.
        return $date !== null && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $stringList = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'properties' => [
                'assignees' => $stringList,
                'statuses' => $stringList,
                'priorities' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']]],
                'tags' => $stringList,
                'projects' => $stringList,
                'date_from' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD or null.'],
                'date_to' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD or null.'],
                'date_kind' => ['type' => ['string', 'null'], 'enum' => ['due', 'done', null]],
                'format' => ['type' => ['string', 'null'], 'enum' => ['excel', 'csv', 'pdf', null]],
            ],
            'required' => ['assignees', 'statuses', 'priorities', 'tags', 'projects', 'date_from', 'date_to', 'date_kind', 'format'],
            'additionalProperties' => false,
        ];
    }
}

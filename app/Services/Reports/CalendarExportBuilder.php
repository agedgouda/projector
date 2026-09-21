<?php

namespace App\Services\Reports;

use App\Models\Project;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds the Campaign Calendar's exports (PDF month grids, flat CSV and Excel) as file bytes.
 * Lives outside ProjectController so anything that isn't a web request — the Slack /report
 * command's queued job — produces exactly the calendar the Campaign Calendar tab's own download
 * buttons do, instead of a second copy that could drift.
 */
class CalendarExportBuilder
{
    /**
     * Resolve this project's calendar items for export, respecting the sub-projects hidden on
     * screen, the active tag filter (with the literal value "none" matching untagged items), the
     * Tasks/Events toggle, and sorted chronologically by each item's effective due date (see
     * buildCalendarGrid()).
     *
     * Drops anything dated before $from — by default the start of the current month, since the
     * on-screen calendar (ProjectCalendar.vue) always opens on the current month too, so a
     * completed campaign's export shouldn't dredge up every past month back to the project's
     * first-ever item. An explicit $from/$to (a Slack request for a specific period) overrides
     * that. An item with no effective due date at all isn't "past" (it's undated), so it's left
     * for each export format's own handling (excludeUndatedItems() for CSV/Excel, silently
     * skipped when building PDF bars — see buildEventRanges()).
     *
     * @param  array<int, string>  $hiddenSubprojects
     * @param  array<int, string>  $tags
     * @param  array<int, string>  $onlyProjects  When non-empty, keeps only items from these projects
     *                                            (the project itself and/or its sub-projects).
     * @return \Illuminate\Support\Collection<int, array{
     *     id: string, name: string|null, content: string|null, type: string, is_task: bool,
     *     project_id: string, project_name: string, is_subproject: bool,
     *     due_at: string|null, external_due_at: string|null, start_at: string|null, task_status: string,
     *     categories: array<int, array{id: string, name: string, color: string}>
     * }>
     */
    public function items(Project $project, array $hiddenSubprojects = [], array $tags = [], bool $showTasks = true, bool $showEvents = true, ?\Illuminate\Support\Carbon $from = null, ?\Illuminate\Support\Carbon $to = null, array $onlyProjects = []): \Illuminate\Support\Collection
    {
        $usesExternalDueDates = $this->usesExternalDueDates($project);
        $project->load(['documents.categories', 'children.documents.categories']);

        $floor = ($from ?? \Illuminate\Support\Carbon::now()->startOfMonth())->copy()->startOfDay();
        $ceiling = $to?->copy()->endOfDay();

        return $project->calendarItems()
            ->reject(fn (array $item) => $item['is_task'] ? ! $showTasks : ! $showEvents)
            ->reject(fn (array $item) => $item['is_subproject'] && in_array($item['project_id'], $hiddenSubprojects, true))
            ->reject(fn (array $item) => $onlyProjects !== [] && ! in_array($item['project_id'], $onlyProjects, true))
            ->reject(function (array $item) use ($tags) {
                if ($tags === []) {
                    return false;
                }
                $categoryIds = array_map(fn (array $category): string => $category['id'], $item['categories']);
                if ($categoryIds === []) {
                    return ! in_array('none', $tags, true);
                }

                return array_intersect($categoryIds, $tags) === [];
            })
            ->reject(function (array $item) use ($usesExternalDueDates, $floor, $ceiling) {
                $date = $this->resolveEffectiveDueDate($item, $usesExternalDueDates);

                if ($date === null) {
                    return false;
                }

                $parsed = \Illuminate\Support\Carbon::parse($date);

                return $parsed->lt($floor) || ($ceiling !== null && $parsed->gt($ceiling));
            })
            ->sortBy(fn (array $item) => $this->resolveEffectiveDueDate($item, $usesExternalDueDates) ?? '')
            ->values();
    }

    public function usesExternalDueDates(Project $project): bool
    {
        $project->loadMissing('client.organization');
        $organization = $project->client?->organization;

        return $organization !== null && $organization->uses_external_due_dates;
    }

    /**
     * Each item's effective due date for calendar purposes — external_due_at when the org uses
     * external due dates and it's set, falling back to due_at otherwise — rather than treating
     * both as independently significant. Falls back (rather than TaskRowFields.vue's strict
     * either/or) because most existing items, and every Event today, have no way to ever get
     * external_due_at set — a strict rule made them vanish from the calendar entirely.
     *
     * @param  array{due_at: string|null, external_due_at: string|null}  $item
     */
    private function resolveEffectiveDueDate(array $item, bool $usesExternalDueDates): ?string
    {
        return ($usesExternalDueDates ? $item['external_due_at'] : null) ?? $item['due_at'];
    }

    /**
     * Drops items with no effective due date (e.g. only external_due_at set on an org that
     * doesn't use external due dates) from the CSV/Excel exports — a flat Date/Title/Tags
     * table has no natural place for a row with a blank date. The PDF grid doesn't need this:
     * such an item simply never gets a bar (see buildEventRanges()).
     *
     * @param  \Illuminate\Support\Collection<int, array{
     *     id: string, name: string|null, content: string|null, type: string, is_task: bool,
     *     project_id: string, project_name: string, is_subproject: bool,
     *     due_at: string|null, external_due_at: string|null, start_at: string|null, task_status: string,
     *     categories: array<int, array{id: string, name: string, color: string}>
     * }>  $items
     * @return \Illuminate\Support\Collection<int, array{
     *     id: string, name: string|null, content: string|null, type: string, is_task: bool,
     *     project_id: string, project_name: string, is_subproject: bool,
     *     due_at: string|null, external_due_at: string|null, start_at: string|null, task_status: string,
     *     categories: array<int, array{id: string, name: string, color: string}>
     * }>
     */
    public function excludeUndatedItems(\Illuminate\Support\Collection $items, bool $usesExternalDueDates): \Illuminate\Support\Collection
    {
        return $items->filter(fn (array $item) => $this->resolveEffectiveDueDate($item, $usesExternalDueDates) !== null)->values();
    }

    /**
     * Build each item's [start, end] date range for the PDF's day-grid bars — mirrors the
     * on-screen calendar's eventRanges computed (ProjectCalendar.vue): a task has no start_at
     * (only ever the single day it's due), same as an event with no start_at set; a start_at
     * after the effective due date (bad data) is clamped to the due date rather than producing
     * a bar that runs backwards. Computed once across every resolved export item regardless of
     * month, so a single subproject/tag color assignment is shared consistently across every
     * month's grid.
     *
     * @param  \Illuminate\Support\Collection<int, array{
     *     id: string, name: string|null, content: string|null, type: string, is_task: bool,
     *     project_id: string, project_name: string, is_subproject: bool,
     *     due_at: string|null, external_due_at: string|null, start_at: string|null, task_status: string,
     *     categories: array<int, array{id: string, name: string, color: string}>
     * }>  $items
     * @return array<int, array{
     *     id: string, name: string, isSubproject: bool, projectName: string, color: string,
     *     startKey: string, endKey: string,
     * }>
     */
    private function buildEventRanges(\Illuminate\Support\Collection $items, bool $usesExternalDueDates): array
    {
        $palette = ['slate', 'red', 'amber', 'emerald', 'blue', 'purple', 'pink', 'orange', 'indigo', 'teal', 'yellow', 'lime', 'green', 'cyan', 'sky', 'violet', 'fuchsia', 'rose'];

        /** @var array<string, string> $subprojectColors */
        $subprojectColors = [];
        foreach ($items as $item) {
            if ($item['is_subproject'] && ! isset($subprojectColors[$item['project_id']])) {
                $subprojectColors[$item['project_id']] = $palette[count($subprojectColors) % count($palette)];
            }
        }

        $ranges = [];

        foreach ($items as $item) {
            $raw = $this->resolveEffectiveDueDate($item, $usesExternalDueDates);
            if ($raw === null) {
                continue;
            }

            $endKey = substr($raw, 0, 10);
            $startKey = $item['start_at'] !== null ? substr($item['start_at'], 0, 10) : $endKey;
            if ($startKey > $endKey) {
                $startKey = $endKey;
            }

            // Same priority as the on-screen calendar's barClasses(): a tag's own color first,
            // then the sub-project color, then plain primary — never subproject-then-tag.
            $tagColor = $item['categories'][0]['color'] ?? null;
            $color = $tagColor ?? ($item['is_subproject'] ? ($subprojectColors[$item['project_id']] ?? 'slate') : 'primary');

            $ranges[] = [
                'id' => $item['id'],
                'name' => $item['name'] ?? 'Untitled',
                'isSubproject' => $item['is_subproject'],
                'projectName' => $item['project_name'],
                'color' => $color,
                'startKey' => $startKey,
                'endKey' => $endKey,
            ];
        }

        return $ranges;
    }

    /**
     * Assign each event range touching the given grid window to a lane — mirrors the on-screen
     * calendar's eventLanes computed: greedy interval packing sorted by start date (ties broken
     * by longer events first), so a range sits at the same vertical row on every day and every
     * week it spans within this grid, matching the on-screen calendar's month view.
     *
     * @param  array<int, array{id: string, startKey: string, endKey: string}>  $ranges
     * @return array<string, int> lane index keyed by range id
     */
    private function assignEventLanes(array $ranges, string $gridStartKey, string $gridEndKey): array
    {
        $relevant = array_values(array_filter(
            $ranges,
            fn (array $range): bool => $range['endKey'] >= $gridStartKey && $range['startKey'] <= $gridEndKey
        ));

        usort($relevant, fn (array $a, array $b): int => $a['startKey'] <=> $b['startKey'] ?: $b['endKey'] <=> $a['endKey']);

        /** @var array<int, string> $laneEndKeys */
        $laneEndKeys = [];
        $lanes = [];

        foreach ($relevant as $range) {
            $lane = null;
            foreach ($laneEndKeys as $i => $laneEndKey) {
                if ($laneEndKey < $range['startKey']) {
                    $lane = $i;
                    break;
                }
            }
            $lane ??= count($laneEndKeys);

            $laneEndKeys[$lane] = $range['endKey'];
            $lanes[$range['id']] = $lane;
        }

        return $lanes;
    }

    /**
     * A single mistyped date (e.g. a spreadsheet import with a year typo like "0206" instead
     * of "2026") would otherwise blow the earliest-to-latest month range out to tens of
     * thousands of months, hanging PDF generation — cap the range at 5 years' worth of
     * month-grids so one bad date can't do that.
     */
    private const MAX_CALENDAR_MONTHS = 60;

    /**
     * Build one calendar grid per month from the earliest to the latest item's effective
     * due date (inclusive, contiguous — so a month with zero items in the middle of the
     * range still gets an empty grid rather than leaving an unexplained gap), stacked
     * vertically in the PDF rather than paginated — a month that doesn't fit on the current
     * page simply flows onto the next, and the following month continues on whatever page
     * that left off on. Falls back to a single empty grid for the current month when there
     * are no items at all. If the raw range would exceed MAX_CALENDAR_MONTHS, it's clamped
     * to that many months centered on the median item's date instead — an item whose date
     * falls outside the clamped range simply won't get a bar, the same as any other date
     * with no grid built for it (see buildMonthGrid()).
     *
     * @param  \Illuminate\Support\Collection<int, array{
     *     id: string, name: string|null, content: string|null, type: string, is_task: bool,
     *     project_id: string, project_name: string, is_subproject: bool,
     *     due_at: string|null, external_due_at: string|null, start_at: string|null, task_status: string,
     *     categories: array<int, array{id: string, name: string, color: string}>
     * }>  $items
     * @return array<int, array{label: string, weeks: array<int, array{
     *     days: array<int, array{day: int, inMonth: bool}>,
     *     laneRows: array<int, array<int, array{name: string, isSubproject: bool, projectName: string, color: string, span: int}|array{skip: true}|null>>,
     * }>}>
     */
    public function buildCalendarMonths(\Illuminate\Support\Collection $items, bool $usesExternalDueDates): array
    {
        $ranges = $this->buildEventRanges($items, $usesExternalDueDates);

        if ($ranges === []) {
            return [$this->buildMonthGrid(\Illuminate\Support\Carbon::now()->startOfMonth(), $ranges)];
        }

        $endKeys = array_column($ranges, 'endKey');
        sort($endKeys);
        $dates = array_map(fn (string $key) => \Illuminate\Support\Carbon::parse($key), $endKeys);

        $cursor = $dates[0]->copy()->startOfMonth();
        $end = $dates[count($dates) - 1]->copy()->startOfMonth();

        if ($cursor->diffInMonths($end) + 1 > self::MAX_CALENDAR_MONTHS) {
            $median = $dates[intdiv(count($dates), 2)]->copy()->startOfMonth();
            $halfSpan = intdiv(self::MAX_CALENDAR_MONTHS, 2);
            $cursor = $median->copy()->subMonths($halfSpan);
            $end = $median->copy()->addMonths($halfSpan);
        }

        $grids = [];
        while ($cursor->lte($end)) {
            $grids[] = $this->buildMonthGrid($cursor->copy(), $ranges);
            $cursor->addMonthNoOverflow();
        }

        return $grids;
    }

    /**
     * Builds one month's day grid plus its event bars — mirrors the on-screen calendar's
     * weeks computed (ProjectCalendar.vue): each week gets its own lane rows, an event
     * spanning multiple days rendered as a single bar (a table cell with colspan) rather
     * than a separate marker per day. laneRows is pre-sliced into exactly 7 slots per lane
     * so the Blade view stays free of loop bookkeeping — a slot is null (empty cell), a bar
     * (rendered with its colspan), or ['skip' => true] (already covered by a preceding
     * bar's colspan, so no `<td>` should be rendered for it at all).
     *
     * @param  array<int, array{
     *     id: string, name: string, isSubproject: bool, projectName: string, color: string,
     *     startKey: string, endKey: string,
     * }>  $ranges
     * @return array{label: string, weeks: array<int, array{
     *     days: array<int, array{day: int, inMonth: bool}>,
     *     laneRows: array<int, array<int, array{name: string, isSubproject: bool, projectName: string, color: string, span: int}|array{skip: true}|null>>,
     * }>}
     */
    private function buildMonthGrid(\Illuminate\Support\Carbon $monthStart, array $ranges): array
    {
        $firstOfMonth = $monthStart->copy()->startOfMonth();
        $startOffset = $firstOfMonth->dayOfWeek;
        $daysInMonth = $firstOfMonth->daysInMonth;
        $totalCells = (int) ceil(($startOffset + $daysInMonth) / 7) * 7;

        $dayCells = [];
        $date = $firstOfMonth->copy()->subDays($startOffset);

        for ($i = 0; $i < $totalCells; $i++) {
            $dayCells[] = [
                'day' => $date->day,
                'inMonth' => $date->month === $firstOfMonth->month,
                'dateKey' => $date->toDateString(),
            ];
            $date->addDay();
        }

        $dayWeeks = array_chunk($dayCells, 7);
        $gridStartKey = $dayCells[0]['dateKey'];
        $gridEndKey = $dayCells[count($dayCells) - 1]['dateKey'];
        $lanes = $this->assignEventLanes($ranges, $gridStartKey, $gridEndKey);

        $weeks = [];
        foreach ($dayWeeks as $weekDays) {
            $weekStartKey = $weekDays[0]['dateKey'];
            $weekEndKey = $weekDays[6]['dateKey'];

            $bars = [];
            foreach ($ranges as $range) {
                if ($range['endKey'] < $weekStartKey || $range['startKey'] > $weekEndKey) {
                    continue;
                }
                if (! isset($lanes[$range['id']])) {
                    continue;
                }

                $segStartKey = $range['startKey'] > $weekStartKey ? $range['startKey'] : $weekStartKey;
                $segEndKey = $range['endKey'] < $weekEndKey ? $range['endKey'] : $weekEndKey;

                $startCol = null;
                $endCol = null;
                foreach ($weekDays as $col => $day) {
                    if ($day['dateKey'] === $segStartKey) {
                        $startCol = $col;
                    }
                    if ($day['dateKey'] === $segEndKey) {
                        $endCol = $col;
                    }
                }
                if ($startCol === null || $endCol === null) {
                    continue;
                }

                $bars[] = [
                    'lane' => $lanes[$range['id']],
                    'startCol' => $startCol,
                    'span' => $endCol - $startCol + 1,
                    'name' => $range['name'],
                    'isSubproject' => $range['isSubproject'],
                    'projectName' => $range['projectName'],
                    'color' => $range['color'],
                ];
            }

            $laneCount = $bars === [] ? 0 : max(array_column($bars, 'lane')) + 1;

            $laneRows = [];
            for ($lane = 0; $lane < $laneCount; $lane++) {
                $slots = array_fill(0, 7, null);
                foreach ($bars as $bar) {
                    if ($bar['lane'] !== $lane) {
                        continue;
                    }
                    $slots[$bar['startCol']] = [
                        'name' => $bar['name'],
                        'isSubproject' => $bar['isSubproject'],
                        'projectName' => $bar['projectName'],
                        'color' => $bar['color'],
                        'span' => $bar['span'],
                    ];
                    for ($col = $bar['startCol'] + 1; $col < $bar['startCol'] + $bar['span']; $col++) {
                        $slots[$col] = ['skip' => true];
                    }
                }
                $laneRows[] = $slots;
            }

            $weeks[] = [
                'days' => array_map(fn (array $day): array => ['day' => $day['day'], 'inMonth' => $day['inMonth']], $weekDays),
                'laneRows' => $laneRows,
            ];
        }

        return [
            'label' => $firstOfMonth->format('F Y'),
            'weeks' => $weeks,
        ];
    }

    /**
     * Each item's effective due date, formatted for display in the CSV/Excel exports.
     *
     * @param  array{due_at: string|null, external_due_at: string|null}  $item
     */
    public function formatCalendarItemDate(array $item, bool $usesExternalDueDates): string
    {
        $raw = $this->resolveEffectiveDueDate($item, $usesExternalDueDates);

        return $raw !== null ? \Illuminate\Support\Carbon::parse(substr($raw, 0, 10))->format('m/d/Y') : '';
    }

    /**
     * @param  array{categories: array<int, array{id: string, name: string, color: string}>}  $item
     */
    public function calendarItemTagNames(array $item): string
    {
        return implode(', ', array_map(fn (array $category): string => $category['name'], $item['categories']));
    }

    /**
     * Build a Date/Title/Tags Excel worksheet from resolved export items — a bolded title
     * row, a bolded header row, then one row per item.
     *
     * @param  \Illuminate\Support\Collection<int, array{
     *     id: string, name: string|null, content: string|null, type: string, is_task: bool,
     *     project_id: string, project_name: string, is_subproject: bool,
     *     due_at: string|null, external_due_at: string|null, start_at: string|null, task_status: string,
     *     categories: array<int, array{id: string, name: string, color: string}>
     * }>  $items
     */
    public function buildCalendarSpreadsheet(Project $project, \Illuminate\Support\Collection $items, bool $usesExternalDueDates): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Calendar');

        $sheet->setCellValue('A1', $project->name.' — Calendar');
        $sheet->mergeCells('A1:C1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getRowDimension(1)->setRowHeight(24);

        $columns = ['A', 'B', 'C'];
        $headers = ['Date', 'Title', 'Tags'];
        foreach ($columns as $i => $col) {
            $cell = $col.'2';
            $sheet->setCellValue($cell, $headers[$i]);
            $style = $sheet->getStyle($cell);
            $style->getFont()->setBold(true);
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
        }

        $rowIndex = 3;
        foreach ($items as $item) {
            $sheet->setCellValue('A'.$rowIndex, $this->formatCalendarItemDate($item, $usesExternalDueDates));
            $sheet->setCellValue('B'.$rowIndex, $item['name'] ?? 'Untitled');
            $sheet->setCellValue('C'.$rowIndex, $this->calendarItemTagNames($item));
            $rowIndex++;
        }

        $sheet->getColumnDimension('A')->setWidth(16);
        $sheet->getColumnDimension('B')->setWidth(48);
        $sheet->getColumnDimension('C')->setWidth(24);

        $sheet->getStyle('A2:C'.($rowIndex - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('E2E8F0'));

        return $spreadsheet;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{
     *     id: string, name: string|null, content: string|null, type: string, is_task: bool,
     *     project_id: string, project_name: string, is_subproject: bool,
     *     due_at: string|null, external_due_at: string|null, start_at: string|null, task_status: string,
     *     categories: array<int, array{id: string, name: string, color: string}>
     * }>  $items
     */
    public function pdfContents(Project $project, \Illuminate\Support\Collection $items): string
    {
        $project->loadMissing('client.organization');
        $organization = $project->client?->organization;
        $usesExternalDueDates = $this->usesExternalDueDates($project);

        return Pdf::loadView('pdfs.calendar', [
            'project' => $project,
            'client' => $project->client,
            'months' => $this->buildCalendarMonths($items, $usesExternalDueDates),
            'usesExternalDueDates' => $usesExternalDueDates,
            'logoPath' => $project->getFirstMedia('logo')?->getPath(),
            'headerImagePath' => $organization?->getFirstMedia('pdf_header')?->getPath(),
            'footerImagePath' => $organization?->getFirstMedia('pdf_footer')?->getPath(),
        ])->setPaper('a4', 'landscape')->output();
    }

    /**
     * A flat Date/Title/Tags table, one row per dated item.
     *
     * @param  \Illuminate\Support\Collection<int, array{
     *     id: string, name: string|null, content: string|null, type: string, is_task: bool,
     *     project_id: string, project_name: string, is_subproject: bool,
     *     due_at: string|null, external_due_at: string|null, start_at: string|null, task_status: string,
     *     categories: array<int, array{id: string, name: string, color: string}>
     * }>  $items
     */
    public function csvContents(Project $project, \Illuminate\Support\Collection $items): string
    {
        $usesExternalDueDates = $this->usesExternalDueDates($project);
        $items = $this->excludeUndatedItems($items, $usesExternalDueDates);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary stream for the CSV.');
        }

        fputcsv($handle, ['Date', 'Title', 'Tags'], ',', '"', '\\');

        foreach ($items as $item) {
            fputcsv($handle, [
                $this->formatCalendarItemDate($item, $usesExternalDueDates),
                $item['name'] ?? 'Untitled',
                $this->calendarItemTagNames($item),
            ], ',', '"', '\\');
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{
     *     id: string, name: string|null, content: string|null, type: string, is_task: bool,
     *     project_id: string, project_name: string, is_subproject: bool,
     *     due_at: string|null, external_due_at: string|null, start_at: string|null, task_status: string,
     *     categories: array<int, array{id: string, name: string, color: string}>
     * }>  $items
     */
    public function excelContents(Project $project, \Illuminate\Support\Collection $items): string
    {
        $usesExternalDueDates = $this->usesExternalDueDates($project);
        $spreadsheet = $this->buildCalendarSpreadsheet($project, $this->excludeUndatedItems($items, $usesExternalDueDates), $usesExternalDueDates);

        ob_start();
        (new Xlsx($spreadsheet))->save('php://output');

        return (string) ob_get_clean();
    }

    public function filename(Project $project, string $extension): string
    {
        return Str::slug($project->name).'-calendar.'.$extension;
    }
}

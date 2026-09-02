<?php

namespace App\Http\Controllers;

use App\Contracts\LlmDriver;
use App\Http\Requests\ProjectRequest;
use App\Jobs\EvaluateProjectDescription;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Services\MeetingTranscriptService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectController extends Controller
{
    public function create(Request $request)
    {
        Gate::authorize('create', Project::class);

        $user = $request->user();
        abort_unless($user instanceof \App\Models\User, 403);

        $orgId = $request->cookie('last_org_id') ?? getPermissionsTeamId();
        $orgId = is_string($orgId) ? $orgId : null;

        $clients = $user->newCollection([$user])->availableClients($orgId);

        $visibleProjects = Project::visibleTo($user, $orgId)->get();

        $parentProjectId = $request->query('parent_project', '');
        $parentProject = $parentProjectId
            ? $visibleProjects->firstWhere('id', $parentProjectId)
            : null;

        $clientName = $request->query('client', '');
        $preselectedClient = $parentProject
            ? $clients->first(fn ($c) => $c->id === $parentProject->client_id)
            : ($clientName
                ? $clients->first(fn ($c) => strcasecmp($c->company_name, $clientName) === 0)
                : null);

        return inertia('Projects/Create', [
            'clients' => $clients,
            'initialName' => $request->query('name', ''),
            'preselectedClient' => $preselectedClient?->only('id', 'company_name'),
            'parentProject' => $parentProject?->only('id', 'name', 'client_id'),
            'backUrl' => $request->query('back', ''),
        ]);
    }

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Project::class);

        $orgId = $request->query('org') ?? $request->cookie('last_org_id') ?? getPermissionsTeamId();

        $user = $request->user();

        // Security: If not a Super-Admin and not assigned to any organization, deny.
        if (! $user->hasRole('super-admin') && $user->organizations()->doesntExist()) {
            abort(404);
        }

        $projects = Project::visibleTo($user, $orgId)
            ->whereHas('client', fn ($q) => $q->where('inactive', false))
            ->latest()
            ->with(['media', 'parent.media'])
            ->get()
            ->withSummary()
            ->map(fn (Project $p) => array_merge($p->toArray(), ['logo_url' => $p->logo_url]));

        return inertia('Projects/Index', [
            'projects' => $projects,
            'clients' => $user->newCollection([$user])->availableClients($orgId),
        ]);
    }

    public function show(Project $project, Request $request, MeetingTranscriptService $service)
    {
        Gate::authorize('view', $project);

        $user = auth()->user();

        // 1. Get projects using your custom collection
        $projects = Project::visibleTo($user)->where('inactive', false)->latest()->with(['media', 'parent.media'])->get()->withDashboardContext()
            ->map(fn (Project $p) => array_merge($p->toArray(), ['logo_url' => $p->logo_url]));

        if ($projects->isEmpty()) {
            return Inertia::render('Dashboard/AccessPending', [
                'user' => $user,
                'message' => 'There are no projects available.',
            ]);
        }

        // 3. Extract clients from the user's own collection
        // (Assuming your User model uses the UserCollection)
        $clients = $user->newCollection([$user])->availableClients();

        $tab = $request->query('tab') ?? $request->cookie('last_active_tab') ?? 'tasks';

        $organization = $project->client->organization;

        setPermissionsTeamId(null);
        $user->unsetRelation('roles');
        $isSuperAdmin = $user->hasRole('super-admin');
        setPermissionsTeamId($organization->id);

        $orgRole = $user->roleInOrganization($organization->id);
        $canManageTranscripts = $isSuperAdmin || in_array($orgRole, ['org-admin', 'project-lead']);

        // Load documents (with all needed relationships) before calling
        // getKanbanDocuments(), so it uses the already-loaded collection instead
        // of lazy-loading documents without eager-loaded relationships.
        $project->load([
            'documents' => fn ($q) => $q->with(['creator', 'editor', 'assignee', 'pendingAssignee', 'lastAiTemplate:id,name', 'categories', 'comments.user'])->withExists('lockedNextWorkflowStep')->latest(),
            'media',
            'parent.media',
            'parent.categories',
            'lifecycleTemplate.lifecycleSteps',
            'currentLifecycleStep',
            'kanbanColumns',
            'categories',
            'children.documents.categories',
            // Populates the assignee filter on the Reports tab's task search form
            // (see resources/js/components/reports/TaskSearchForm.vue) — same
            // merged users+invitations list used by the document assignee picker.
            'client.organization.users',
            'client.organization.invitations',
        ]);
        $project->withResolvedFamilyCategories();

        $kanbanData = [(string) $project->id => $project->getKanbanDocuments()];

        cookie()->queue(cookie()->forever('last_project_id', $project->id));
        cookie()->queue(cookie()->forever('last_active_tab', $tab));
        cookie()->queue(cookie()->forever('last_org_id', (string) $organization->id));

        return Inertia::render('Projects/Show', [
            'projects' => $projects,
            'currentProject' => array_merge($project->toArray(), ['logo_url' => $project->logo_url]),
            'kanbanData' => $kanbanData,
            'calendarItems' => $project->calendarItems(),
            'activeTab' => $tab,
            'clients' => $clients,
            'documentTypeCatalog' => $project->documentTypeCatalog()->values(),
            'canManageTranscripts' => $canManageTranscripts,
            'canManageProject' => Gate::allows('update', $project),
            'meetingProvider' => $organization->meeting_provider,
            'googlePickerConfigured' => filled(config('services.google.client_id'))
                && filled(config('services.google.client_secret'))
                && filled(config('services.google.api_key'))
                && filled(config('services.google.app_id')),
            'googleApiKey' => config('services.google.api_key'),
            'googleAppId' => config('services.google.app_id'),
            'recordingsData' => Inertia::defer(function () use ($project, $service, $organization, $canManageTranscripts) {
                $importedIds = $project->documents()
                    ->whereNotNull('metadata->recording_id')
                    ->get(['metadata'])
                    ->pluck('metadata.recording_id')
                    ->filter()
                    ->values();

                $crossProjectImportedIds = Document::whereNotNull('metadata->recording_id')
                    ->where('project_id', '!=', $project->id)
                    ->get(['metadata'])
                    ->pluck('metadata.recording_id')
                    ->filter()
                    ->diff($importedIds)
                    ->values();

                $dismissedIds = $project->dismissedRecordings()->pluck('recording_id');

                $recordings = [];
                $providerError = null;

                if ($organization->meeting_provider) {
                    try {
                        $all = $service->listRecordings($organization, now()->subDays(30));
                        $recordings = array_values(array_filter(
                            $all,
                            fn ($r) => ! $dismissedIds->contains($r['id'])
                        ));
                    } catch (\Throwable $e) {
                        $providerError = $e->getMessage();
                    }
                }

                return [
                    'recordings' => $recordings,
                    'importedIds' => $importedIds,
                    'crossProjectImportedIds' => $crossProjectImportedIds,
                    'providerError' => $providerError,
                    'canManage' => $canManageTranscripts,
                ];
            })->once(),
        ]);
    }

    /**
     * Resolve this project's calendar items for export, respecting the sub-project
     * filter currently applied on screen (passed as ?hidden_subprojects[]=...), the
     * active tag filter (passed as ?tags[]=..., with the literal value "none" matching
     * untagged items), the Tasks/Events toggle (passed as ?show_tasks=&show_events=,
     * each defaulting to true so an older/bare export link still shows everything), and
     * sorted chronologically by each item's effective due date (see buildCalendarGrid()).
     *
     * @return \Illuminate\Support\Collection<int, array{
     *     id: string, name: string|null, content: string|null, type: string, is_task: bool,
     *     project_id: string, project_name: string, is_subproject: bool,
     *     due_at: string|null, external_due_at: string|null, task_status: string,
     *     categories: array<int, array{id: string, name: string, color: string}>
     * }>
     */
    private function resolveCalendarExportItems(Request $request, Project $project, bool $usesExternalDueDates): \Illuminate\Support\Collection
    {
        $project->load(['documents.categories', 'children.documents.categories']);

        $hidden = array_map('strval', (array) $request->query('hidden_subprojects', []));
        $tags = array_map('strval', (array) $request->query('tags', []));
        $showTasks = $request->boolean('show_tasks', true);
        $showEvents = $request->boolean('show_events', true);

        return $project->calendarItems()
            ->reject(fn (array $item) => $item['is_task'] ? ! $showTasks : ! $showEvents)
            ->reject(fn (array $item) => $item['is_subproject'] && in_array($item['project_id'], $hidden, true))
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
            ->sortBy(fn (array $item) => $this->resolveEffectiveDueDate($item, $usesExternalDueDates) ?? '')
            ->values();
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
     * Filters already-resolved export items down to just those whose effective due date falls
     * within the given month — used by the CSV/Excel exports, which (unlike the PDF's
     * buildCalendarGrid()) show a flat table rather than a day grid, so there's no leading/
     * trailing padding week to intentionally let adjacent-month items land on.
     *
     * @param  \Illuminate\Support\Collection<int, array{
     *     id: string, name: string|null, content: string|null, type: string, is_task: bool,
     *     project_id: string, project_name: string, is_subproject: bool,
     *     due_at: string|null, external_due_at: string|null, task_status: string,
     *     categories: array<int, array{id: string, name: string, color: string}>
     * }>  $items
     * @return \Illuminate\Support\Collection<int, array{
     *     id: string, name: string|null, content: string|null, type: string, is_task: bool,
     *     project_id: string, project_name: string, is_subproject: bool,
     *     due_at: string|null, external_due_at: string|null, task_status: string,
     *     categories: array<int, array{id: string, name: string, color: string}>
     * }>
     */
    private function filterItemsToMonth(\Illuminate\Support\Collection $items, bool $usesExternalDueDates, \Illuminate\Support\Carbon $month): \Illuminate\Support\Collection
    {
        $targetKey = $month->format('Y-m');

        return $items->filter(function (array $item) use ($usesExternalDueDates, $targetKey) {
            $effective = $this->resolveEffectiveDueDate($item, $usesExternalDueDates);

            return $effective !== null && substr($effective, 0, 7) === $targetKey;
        })->values();
    }

    /**
     * Build the calendar grid (weeks of day cells, each holding the due-date markers
     * that fall on it) for a single target month from resolved export items — the
     * same month currently shown on screen — so the PDF export visually matches the
     * on-screen calendar instead of being a flat list. Each item contributes at most
     * one marker, on its effective due date (see resolveEffectiveDueDate()).
     *
     * @param  \Illuminate\Support\Collection<int, array{
     *     id: string, name: string|null, content: string|null, type: string, is_task: bool,
     *     project_id: string, project_name: string, is_subproject: bool,
     *     due_at: string|null, external_due_at: string|null, task_status: string,
     *     categories: array<int, array{id: string, name: string, color: string}>
     * }>  $items
     * @return array{label: string, weeks: array<int, array<int, array{
     *     day: int, inMonth: bool,
     *     markers: array<int, array{name: string, isSubproject: bool, projectName: string, color: string}>
     * }>>}
     */
    private function buildCalendarGrid(\Illuminate\Support\Collection $items, bool $usesExternalDueDates, \Illuminate\Support\Carbon $month): array
    {
        $palette = ['slate', 'red', 'amber', 'emerald', 'blue', 'purple', 'pink', 'orange', 'indigo', 'teal'];

        /** @var array<string, string> $subprojectColors */
        $subprojectColors = [];
        foreach ($items as $item) {
            if ($item['is_subproject'] && ! isset($subprojectColors[$item['project_id']])) {
                $subprojectColors[$item['project_id']] = $palette[count($subprojectColors) % count($palette)];
            }
        }

        /** @var array<string, array<int, array{name: string, isSubproject: bool, projectName: string, color: string}>> $markersByDate */
        $markersByDate = [];

        foreach ($items as $item) {
            $raw = $this->resolveEffectiveDueDate($item, $usesExternalDueDates);
            if ($raw === null) {
                continue;
            }

            $date = \Illuminate\Support\Carbon::parse(substr($raw, 0, 10));
            $key = $date->toDateString();

            // Same priority as the on-screen calendar's barClasses(): a tag's own color first,
            // then the sub-project color, then plain primary — never subproject-then-tag.
            $tagColor = $item['categories'][0]['color'] ?? null;
            $color = $tagColor ?? ($item['is_subproject'] ? ($subprojectColors[$item['project_id']] ?? 'slate') : 'primary');

            $markersByDate[$key][] = [
                'name' => $item['name'] ?? 'Untitled',
                'isSubproject' => $item['is_subproject'],
                'projectName' => $item['project_name'],
                'color' => $color,
            ];
        }

        return $this->buildMonthGrid($month, $markersByDate);
    }

    /**
     * @param  array<string, array<int, array{name: string, isSubproject: bool, projectName: string, color: string}>>  $markersByDate
     * @return array{label: string, weeks: array<int, array<int, array{
     *     day: int, inMonth: bool,
     *     markers: array<int, array{name: string, isSubproject: bool, projectName: string, color: string}>
     * }>>}
     */
    private function buildMonthGrid(\Illuminate\Support\Carbon $monthStart, array $markersByDate): array
    {
        $firstOfMonth = $monthStart->copy()->startOfMonth();
        $startOffset = $firstOfMonth->dayOfWeek;
        $daysInMonth = $firstOfMonth->daysInMonth;
        $totalCells = (int) ceil(($startOffset + $daysInMonth) / 7) * 7;

        $cells = [];
        $date = $firstOfMonth->copy()->subDays($startOffset);

        for ($i = 0; $i < $totalCells; $i++) {
            $cells[] = [
                'day' => $date->day,
                'inMonth' => $date->month === $firstOfMonth->month,
                'markers' => $markersByDate[$date->toDateString()] ?? [],
            ];
            $date->addDay();
        }

        return [
            'label' => $firstOfMonth->format('F Y'),
            'weeks' => array_chunk($cells, 7),
        ];
    }

    /**
     * Resolve the month to export — whatever the user currently has visible on
     * screen, passed as ?month=YYYY-MM, defaulting to the current month if absent
     * or malformed.
     */
    private function resolveTargetMonth(Request $request): \Illuminate\Support\Carbon
    {
        $raw = $request->query('month');

        if (is_string($raw) && preg_match('/^\d{4}-\d{2}$/', $raw)) {
            $parsed = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $raw.'-01');
            if ($parsed !== null) {
                return $parsed->startOfMonth();
            }
        }

        return \Illuminate\Support\Carbon::now()->startOfMonth();
    }

    /**
     * Export the project's calendar (due-date items, including visible sub-projects)
     * for the currently-viewed month as a branded, calendar-styled PDF matching the
     * on-screen calendar.
     */
    public function exportCalendarPdf(Request $request, Project $project): \Illuminate\Http\Response
    {
        Gate::authorize('view', $project);

        $month = $this->resolveTargetMonth($request);

        $project->loadMissing('client.organization');
        $organization = $project->client?->organization;
        $usesExternalDueDates = $organization !== null && $organization->uses_external_due_dates;

        $items = $this->resolveCalendarExportItems($request, $project, $usesExternalDueDates);
        $grid = $this->buildCalendarGrid($items, $usesExternalDueDates, $month);

        $pdf = Pdf::loadView('pdfs.calendar', [
            'project' => $project,
            'client' => $project->client,
            'month' => $grid,
            'usesExternalDueDates' => $usesExternalDueDates,
            'logoPath' => $project->getFirstMedia('logo')?->getPath(),
            'headerImagePath' => $organization?->getFirstMedia('pdf_header')?->getPath(),
            'footerImagePath' => $organization?->getFirstMedia('pdf_footer')?->getPath(),
        ])->setPaper('a4', 'landscape');

        $filename = Str::slug($project->name).'-calendar-'.$month->format('Y-m');

        return $pdf->download($filename.'.pdf');
    }

    /**
     * Export the project's calendar items (visible sub-projects, tags, and Tasks/Events
     * toggle all respected — see resolveCalendarExportItems()) for the currently-viewed
     * month as a flat Date/Title/Tags CSV, one row per item, rather than a day-grid layout.
     */
    public function exportCalendarCsv(Request $request, Project $project): StreamedResponse
    {
        Gate::authorize('view', $project);

        $month = $this->resolveTargetMonth($request);

        $project->loadMissing('client.organization');
        $organization = $project->client?->organization;
        $usesExternalDueDates = $organization !== null && $organization->uses_external_due_dates;

        $items = $this->filterItemsToMonth(
            $this->resolveCalendarExportItems($request, $project, $usesExternalDueDates),
            $usesExternalDueDates,
            $month
        );

        $filename = Str::slug($project->name).'-calendar-'.$month->format('Y-m').'.csv';

        $callback = function () use ($items, $usesExternalDueDates) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['Date', 'Title', 'Tags'], ',', '"', '\\');

            foreach ($items as $item) {
                fputcsv($handle, [
                    $this->formatCalendarItemDate($item, $usesExternalDueDates),
                    $item['name'] ?? 'Untitled',
                    $this->calendarItemTagNames($item),
                ], ',', '"', '\\');
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Export the project's calendar items (visible sub-projects, tags, and Tasks/Events
     * toggle all respected — see resolveCalendarExportItems()) for the currently-viewed
     * month as a flat Date/Title/Tags Excel workbook, one row per item, rather than a
     * day-grid layout.
     */
    public function exportCalendarExcel(Request $request, Project $project): StreamedResponse
    {
        Gate::authorize('view', $project);

        $month = $this->resolveTargetMonth($request);

        $project->loadMissing('client.organization');
        $organization = $project->client?->organization;
        $usesExternalDueDates = $organization !== null && $organization->uses_external_due_dates;

        $items = $this->filterItemsToMonth(
            $this->resolveCalendarExportItems($request, $project, $usesExternalDueDates),
            $usesExternalDueDates,
            $month
        );

        $spreadsheet = $this->buildCalendarSpreadsheet($project, $month, $items, $usesExternalDueDates);
        $writer = new Xlsx($spreadsheet);

        $filename = Str::slug($project->name).'-calendar-'.$month->format('Y-m').'.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Each item's effective due date, formatted for display in the CSV/Excel exports.
     *
     * @param  array{due_at: string|null, external_due_at: string|null}  $item
     */
    private function formatCalendarItemDate(array $item, bool $usesExternalDueDates): string
    {
        $raw = $this->resolveEffectiveDueDate($item, $usesExternalDueDates);

        return $raw !== null ? \Illuminate\Support\Carbon::parse(substr($raw, 0, 10))->format('M j, Y') : '';
    }

    /**
     * @param  array{categories: array<int, array{id: string, name: string, color: string}>}  $item
     */
    private function calendarItemTagNames(array $item): string
    {
        return implode(', ', array_map(fn (array $category): string => $category['name'], $item['categories']));
    }

    /**
     * Build a Date/Title/Tags Excel worksheet from resolved, month-filtered export items —
     * a bolded title row, a bolded header row, then one row per item.
     *
     * @param  \Illuminate\Support\Collection<int, array{
     *     id: string, name: string|null, content: string|null, type: string, is_task: bool,
     *     project_id: string, project_name: string, is_subproject: bool,
     *     due_at: string|null, external_due_at: string|null, task_status: string,
     *     categories: array<int, array{id: string, name: string, color: string}>
     * }>  $items
     */
    private function buildCalendarSpreadsheet(Project $project, \Illuminate\Support\Carbon $month, \Illuminate\Support\Collection $items, bool $usesExternalDueDates): Spreadsheet
    {
        $monthLabel = $month->format('F Y');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(Str::limit($monthLabel, 31, ''));

        $sheet->setCellValue('A1', $project->name.' — '.$monthLabel);
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
     * Store a newly created project.
     * Uses ProjectRequest to handle context-switching and authorization.
     */
    public function store(ProjectRequest $request)
    {
        $request->validate([
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:5120'],
        ]);

        try {
            // Validation and Authorization already handled by ProjectRequest
            // But we'll call Gate::authorize here to ensure the standard 403 flow

            Gate::authorize('create', Project::class);

            $orgId = getPermissionsTeamId();
            $org = \App\Models\Organization::find($orgId);
            if ($org && ($block = \App\Services\MembershipGuard::check($org, 'projects'))) {
                return $block;
            }

            $validated = $request->validated();

            $project = Project::create($validated);

            if ($request->hasFile('logo')) {
                $project->addMediaFromRequest('logo')->toMediaCollection('logo');
            }

            // The frontend already evaluates description quality before submitting (see
            // ProjectEntryForm.vue's pre-submission check) — only fall back to the async
            // job when that didn't happen, so the badge doesn't need a later page refresh.
            if (! empty($project->description) && empty($validated['description_quality'])) {
                EvaluateProjectDescription::dispatch($project);
            }

            return redirect()->back()->with('success', 'Project successfully created.');

        } catch (\Exception $e) {
            \Log::error('[ProjectController] Store failed', [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 300),
            ]);
            throw $e;
        }
    }

    public function update(ProjectRequest $request, Project $project)
    {
        try {
            Gate::authorize('update', $project);

            $validated = $request->validated();

            $descriptionChanged = array_key_exists('description', $validated) && $validated['description'] !== $project->description;

            // The frontend already evaluates description quality before submitting (see
            // ProjectEntryForm.vue's pre-submission check) — only fall back to the async
            // job when that didn't happen, so the badge doesn't need a later page refresh.
            $hasFreshQuality = $descriptionChanged && ! empty($validated['description_quality']);

            if ($descriptionChanged) {
                $validated['description_quality'] = $hasFreshQuality ? $validated['description_quality'] : null;
            } else {
                // The form always sends this field, but it's only meaningful when the
                // description actually changed — otherwise leave the stored value alone.
                unset($validated['description_quality']);
            }

            $project->update($validated);

            if ($descriptionChanged && ! $hasFreshQuality) {
                EvaluateProjectDescription::dispatch($project);
            }

            return redirect()->back()->with('success', 'Project updated successfully.');

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {

            \Log::error('[ControllerDebug] Authorization Failed', [
                'user_id' => auth()->id(),
                'project_org_id' => $project->organization_id, // Hits our new accessor
                'active_team_id' => getPermissionsTeamId(),
            ]);
            throw $e;
        }
    }

    public function destroy(Project $project)
    {
        setPermissionsTeamId($project->organization_id);

        Gate::authorize('delete', $project);

        if ($project->children()->exists()) {
            return back()->with('error', 'This project has sub-projects and cannot be deleted. Remove or reassign its sub-projects first.');
        }

        $project->delete();
        $message = 'Project was successfully deleted.';

        $redirectTo = request()->get('redirect_to');
        if ($redirectTo && str_starts_with($redirectTo, '/') && ! str_starts_with($redirectTo, '//')) {
            return redirect($redirectTo)->with('success', $message);
        }

        return redirect()->route('dashboard')->with('success', $message);
    }

    public function reactivate(Project $project): RedirectResponse
    {
        setPermissionsTeamId($project->organization_id);
        Gate::authorize('update', $project);

        $project->update(['inactive' => false]);

        $project->client->update(['inactive' => false]);

        return back()->with('success', 'Project reactivated.');
    }

    public function storeDocument(Request $request, Project $project)
    {
        setPermissionsTeamId($project->organization_id);

        Gate::authorize('update', $project);

        $validated = $request->validate([
            'name' => 'required|string',
            'type' => 'required|string',
            'content' => 'required|string',
        ]);

        $project->documents()->create($validated);

        return back()->with('success', 'Document added and indexed.');
    }

    public function evaluateDescription(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'required|string|min:1',
            'client_id' => 'required|exists:clients,id',
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $client->organization?->applyDriverConfig();

        /** @var LlmDriver $llmDriver */
        $llmDriver = app(LlmDriver::class);

        $systemPrompt = 'You evaluate project descriptions to determine if they provide useful context for AI document generation. Set "title" to exactly "good" if the description conveys what the project is, who it is for, or what it aims to achieve — even a short, specific description qualifies. Set "title" to exactly "vague" only if the description is so generic that an AI could not meaningfully tailor output to it (e.g. "A project", "Internal tool", "New website"). When vague, use the "criteria" array to list 2-3 short, actionable suggestions for what the user could add to improve it — write them as helpful prompts, not criticisms. Use the content field for a one-sentence explanation.';
        $userPrompt = "Evaluate this project description: \"{$validated['description']}\"\n\nCRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: \"title\", \"content\", and \"criteria\".";

        try {
            $result = $llmDriver->call($systemPrompt, $userPrompt);

            if (($result['status'] ?? '') !== 'success' || empty($result['content'])) {
                return response()->json(['quality' => 'good', 'suggestions' => []]);
            }

            $item = $result['content'][0];
            $verdict = strtolower(trim($item['title'] ?? ''));
            $quality = in_array($verdict, ['good', 'vague']) ? $verdict : 'good';
            $suggestions = $quality === 'vague' ? ($item['criteria'] ?? []) : [];

            return response()->json(['quality' => $quality, 'suggestions' => $suggestions]);
        } catch (\Throwable) {
            return response()->json(['quality' => 'good', 'suggestions' => []]);
        }
    }
}

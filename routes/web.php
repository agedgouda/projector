<?php

use App\Http\Controllers\AiTemplateController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\BugReportController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientLogoController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ContentUploadController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\KanbanColumnController;
use App\Http\Controllers\MeetingTranscriptController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationLoginController;
use App\Http\Controllers\OrganizationLogoController;
use App\Http\Controllers\OrganizationPdfBrandingController;
use App\Http\Controllers\OrganizationRegistrationController;
use App\Http\Controllers\OrganizationSetupController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectLogoController;
use App\Http\Controllers\ProjectTypeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/invite/{token}', [InvitationController::class, 'accept'])
    ->name('invite');

Route::get('/login/{organization}', [OrganizationLoginController::class, 'create'])
    ->name('organization.login');
Route::post('/login/{organization}', [OrganizationLoginController::class, 'store'])
    ->name('organization.login.store');

Route::get('/register/{organization}', [OrganizationRegistrationController::class, 'create'])
    ->name('organization.register');
Route::post('/register/{organization}', [OrganizationRegistrationController::class, 'store'])
    ->name('organization.register.store');

Route::get('/', function (Request $request) {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    // The marketing site has no way to log in — on the local Herd domain specifically,
    // send guests straight to the login form instead. Every other host (production,
    // staging, etc.) keeps redirecting to the marketing site.
    return $request->getHost() === 'projector.test'
        ? redirect()->route('login')
        : redirect()->away('https://about.projecthq.app/');
})->name('home');

Route::post('/log-connection-issue', function (Request $request) {
    Log::warning('Frontend WebSocket Issue Detected', [
        'user_id' => auth()->id(),
        'state' => $request->input('state'),
        'last_error' => $request->input('error'),
        'user_agent' => $request->userAgent(),
    ]);

    return response()->json(['status' => 'logged']);
})->middleware(['auth', 'throttle:60,1']);

// Hit by useAiProcessing's periodic reconciliation poll whenever it discovers a document it
// was showing as "processing" had actually already finished — a missed .DocumentProcessingUpdate
// broadcast (the socket itself may never have dropped; see /log-connection-issue for that case).
// Diagnostic-only, so a client that never checks in just means nothing to report — no alerting
// depends on this ever firing.
Route::post('/log-stale-processing', function (Request $request) {
    Log::warning('Stale AI processing indicator self-corrected', [
        'user_id' => auth()->id(),
        'project_id' => $request->input('project_id'),
        'document_ids' => $request->input('document_ids'),
        'stuck_for_ms' => $request->input('stuck_for_ms'),
        'user_agent' => $request->userAgent(),
    ]);

    return response()->json(['status' => 'logged']);
})->middleware(['auth', 'throttle:60,1']);

// Hit by useDocumentEditor's uploadFile() whenever a content-upload request fails (network
// error, server error, validation rejection, etc.) — the client is often the only place that
// ever sees the real response, since a hard rejection at the production web server/PHP layer
// (e.g. a file over its own upload cap) can happen before Laravel's own app-level logging
// would ever run.
Route::post('/log-upload-error', function (Request $request) {
    Log::error('Content upload failed', [
        'user_id' => auth()->id(),
        'project_id' => $request->input('project_id'),
        'file_name' => $request->input('file_name'),
        'file_size' => $request->input('file_size'),
        'file_type' => $request->input('file_type'),
        'status' => $request->input('status'),
        'message' => $request->input('message'),
        'user_agent' => $request->userAgent(),
    ]);

    return response()->json(['status' => 'logged']);
})->middleware(['auth', 'throttle:60,1']);

// Hit by the idle-session-timeout modal's "Stay Logged In" action — merely being an
// authenticated request is enough to refresh the session's last-activity timestamp via the
// framework's own session middleware, so this needs no body beyond that.
Route::post('/session/keep-alive', function () {
    return response()->noContent();
})->middleware(['auth', 'throttle:30,1'])->name('session.keep-alive');

/**
 * Access Pending:
 * A fallback page for users who are logged in but not yet assigned
 * to an organization or lack a global role.
 */
Route::get('access-pending', function () {
    return Inertia::render('Dashboard/AccessPending');
})->middleware(['auth'])->name('dashboard.pending');

Route::middleware(['auth'])->group(function () {
    Route::get('/organization/setup', [OrganizationSetupController::class, 'create'])->name('organization.setup');
    Route::post('/organization/setup', [OrganizationSetupController::class, 'store'])->name('organization.setup.store');

    /**
     * 1. Management & Admin Area
     * We allow both Global Super Admins and Organization Admins here.
     * Note: Use the pipe '|' to allow multiple roles in Spatie's middleware.
     */
    Route::post('/organizations/{organization}/users', [OrganizationController::class, 'addUser'])
        ->name('organizations.users.store');

    Route::get('/faq', [FaqController::class, 'index'])->name('faq.index');

    // Must be reachable regardless of role — by the time this runs, the session's current
    // user is the impersonated target (not the admin who started it), not necessarily a
    // super-admin.
    Route::delete('/impersonate', [ImpersonationController::class, 'destroy'])
        ->name('impersonate.destroy');

    Route::middleware(['role:super-admin'])->group(function () {
        Route::post('/faq', [FaqController::class, 'store'])->name('faq.store');
        Route::put('/faq/{faq}', [FaqController::class, 'update'])->name('faq.update');
        Route::delete('/faq/{faq}', [FaqController::class, 'destroy'])->name('faq.destroy');
        Route::get('/users/list', [UserController::class, 'list'])
            ->name('users.list');
        Route::post('/users/{user}/promote', [UserController::class, 'promote'])
            ->name('users.promote');
        Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'store'])
            ->name('users.impersonate');
        Route::get('/bug-reports', [BugReportController::class, 'index'])
            ->name('bug-reports.index');
        Route::patch('/bug-reports/{bugReport}', [BugReportController::class, 'update'])
            ->name('bug-reports.update');
        Route::get('/admin/organizations', [OrganizationController::class, 'adminIndex'])
            ->name('admin.organizations.index');
        Route::patch('/admin/organizations/{organization}/tier', [OrganizationController::class, 'updateTier'])
            ->name('admin.organizations.update-tier');
    });

    Route::get('/bug-reports/create', [BugReportController::class, 'create'])
        ->name('bug-reports.create');
    Route::post('/bug-reports', [BugReportController::class, 'store'])
        ->name('bug-reports.store');

    Route::middleware(['org-role:org-admin'])->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');

        Route::resource('roles', RoleController::class);
        Route::delete('/roles/{role}/users/{user}', [RoleController::class, 'unassignUser'])
            ->name('roles.users.destroy');

        Route::resource('project-types', ProjectTypeController::class);
        Route::post('/project-types/{projectType}/duplicate', [ProjectTypeController::class, 'duplicate'])
            ->name('project-types.duplicate');
        Route::resource('transformation-library', AiTemplateController::class)
            ->parameters(['transformation-library' => 'aiTemplate']);
        Route::post('/transformation-library/{aiTemplate}/duplicate', [AiTemplateController::class, 'duplicate'])
            ->name('transformation-library.duplicate');
        Route::post('/transformation-library/generate-prompts', [AiTemplateController::class, 'generatePrompts'])
            ->middleware('throttle:20,1')
            ->name('transformation-library.generate-prompts');
        Route::resource('tasks', TaskController::class);
    });

    // Main Entry Point
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Organizations are accessible to any org member; policy handles per-action authorization.
    Route::resource('organizations', OrganizationController::class);
    Route::post('/organizations/{organization}/logo', [OrganizationLogoController::class, 'store'])
        ->name('organizations.logo.store');
    Route::delete('/organizations/{organization}/logo', [OrganizationLogoController::class, 'destroy'])
        ->name('organizations.logo.destroy');
    Route::post('/organizations/{organization}/pdf-branding/{type}', [OrganizationPdfBrandingController::class, 'store'])
        ->whereIn('type', ['header', 'footer'])
        ->name('organizations.pdf-branding.store');
    Route::delete('/organizations/{organization}/pdf-branding/{type}', [OrganizationPdfBrandingController::class, 'destroy'])
        ->whereIn('type', ['header', 'footer'])
        ->name('organizations.pdf-branding.destroy');
    Route::post('/organizations/{organization}/invite', [InvitationController::class, 'store'])
        ->name('organizations.invite');
    Route::post('/organizations/{organization}/invitations/{invitation}/resend', [InvitationController::class, 'resend'])
        ->name('organizations.invitations.resend');

    // Status Meetings (org-level, org resolved from cookie/query like Organizations)
    Route::get('/status-meetings', [\App\Http\Controllers\OrgDocumentController::class, 'index'])
        ->name('status-meetings.index');

    // Org Documents (status meetings CRUD, nested under org for authorization)
    Route::post('/organizations/{organization}/import-recording', [\App\Http\Controllers\OrgDocumentController::class, 'importFromRecording'])
        ->name('organizations.import-recording');

    Route::prefix('organizations/{organization}/documents')->name('organizations.documents.')->group(function () {
        Route::get('/create', [\App\Http\Controllers\OrgDocumentController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\OrgDocumentController::class, 'store'])->name('store');
        Route::get('/{orgDocument}', [\App\Http\Controllers\OrgDocumentController::class, 'show'])->name('show');
        Route::patch('/{orgDocument}', [\App\Http\Controllers\OrgDocumentController::class, 'update'])->name('update');
        Route::delete('/{orgDocument}', [\App\Http\Controllers\OrgDocumentController::class, 'destroy'])->name('destroy');
        Route::post('/{orgDocument}/import-recording', [\App\Http\Controllers\OrgDocumentController::class, 'importRecording'])->name('import-recording');
        Route::post('/{orgDocument}/process-draft', [\App\Http\Controllers\OrgDocumentController::class, 'processDraft'])->name('process-draft');
        Route::patch('/{orgDocument}/save-draft', [\App\Http\Controllers\OrgDocumentController::class, 'saveDraft'])->name('save-draft');
        Route::post('/{orgDocument}/commit-draft', [\App\Http\Controllers\OrgDocumentController::class, 'commitDraft'])->name('commit-draft');
        Route::get('/{orgDocument}/draft/{groupId}', [\App\Http\Controllers\OrgDocumentController::class, 'showDraftGroup'])->name('draft.show');
        Route::post('/{orgDocument}/draft/{groupId}/commit', [\App\Http\Controllers\OrgDocumentController::class, 'commitDraftGroup'])->name('draft.commit');
    });

    Route::post('/projects/evaluate-description', [ProjectController::class, 'evaluateDescription'])
        ->middleware('throttle:20,1')
        ->name('projects.evaluate-description');

    /**
     * 2. Client & Project Management
     * This uses your updated 'EnsureUserCanAccessClient' middleware (aliased as client.access)
     */
    // Logo routes use their own Gate::authorize — no client.access needed
    Route::post('/clients/{client}/logo', [ClientLogoController::class, 'store'])
        ->name('clients.logo.store');
    Route::delete('/clients/{client}/logo', [ClientLogoController::class, 'destroy'])
        ->name('clients.logo.destroy');
    Route::post('/projects/{project}/logo', [ProjectLogoController::class, 'store'])
        ->name('projects.logo.store');
    Route::delete('/projects/{project}/logo', [ProjectLogoController::class, 'destroy'])
        ->name('projects.logo.destroy');

    Route::middleware(['client.access'])->group(function () {
        Route::resource('clients', ClientController::class);
        Route::resource('comments', CommentController::class);
        Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
        Route::resource('projects', ProjectController::class);

        Route::post('/projects/{project}/generate', [ProjectController::class, 'generate'])
            ->name('projects.generate');

        Route::patch('/projects/{project}/reactivate', [ProjectController::class, 'reactivate'])
            ->name('projects.reactivate');

        // 3. Project Documents & Transcripts
        Route::prefix('projects/{project}')->name('projects.')->group(function () {
            Route::match(['get', 'post'], '/documents/search', [DocumentController::class, 'search'])
                ->middleware('throttle:30,1')
                ->name('documents.search');
            Route::post('/content-uploads', [ContentUploadController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('content-uploads.store');
            Route::post('/documents/{document}/reprocess', [DocumentController::class, 'reprocess'])
                ->middleware('throttle:10,1')
                ->name('documents.reprocess');
            Route::post('/documents/{document}/transition', [DocumentController::class, 'transition'])
                ->middleware('throttle:10,1')
                ->name('documents.transition');
            Route::get('/documents/{document}/transition-options', [DocumentController::class, 'transitionOptions'])
                ->name('documents.transitionOptions');
            Route::patch('/documents/{document}/attributes', [DocumentController::class, 'updateAttributes'])
                ->name('documents.updateAttributes');
            Route::get('/documents/{document}/export-pdf', [DocumentController::class, 'exportPdf'])
                ->name('documents.exportPdf');
            Route::get('/documents/{document}/export-word', [DocumentController::class, 'exportWord'])
                ->name('documents.exportWord');

            Route::get('/reports/tasks', [ReportController::class, 'projectTasks'])
                ->name('reports.tasks');
            Route::get('/reports/tasks/export-pdf', [ReportController::class, 'exportTasksPdf'])
                ->name('reports.tasks.exportPdf');
            Route::get('/reports/tasks/export-word', [ReportController::class, 'exportTasksWord'])
                ->name('reports.tasks.exportWord');
            Route::get('/reports/tasks/export-excel', [ReportController::class, 'exportTasksExcel'])
                ->name('reports.tasks.exportExcel');

            Route::get('/calendar/export-pdf', [ProjectController::class, 'exportCalendarPdf'])
                ->name('calendar.exportPdf');
            Route::get('/calendar/export-csv', [ProjectController::class, 'exportCalendarCsv'])
                ->name('calendar.exportCsv');
            Route::get('/calendar/export-excel', [ProjectController::class, 'exportCalendarExcel'])
                ->name('calendar.exportExcel');

            Route::resource('documents', DocumentController::class);

            Route::post('/kanban-columns', [KanbanColumnController::class, 'store'])
                ->name('kanban-columns.store');
            Route::patch('/kanban-columns/{kanbanColumn}', [KanbanColumnController::class, 'update'])
                ->name('kanban-columns.update');
            Route::delete('/kanban-columns/{kanbanColumn}', [KanbanColumnController::class, 'destroy'])
                ->name('kanban-columns.destroy');

            Route::get('/transcripts', [MeetingTranscriptController::class, 'index'])
                ->name('transcripts.index');
            Route::post('/transcripts', [MeetingTranscriptController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('transcripts.store');
            Route::post('/transcripts/dismiss', [MeetingTranscriptController::class, 'destroy'])
                ->name('transcripts.destroy');
        });
    });
});

Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');

/**
 * Mobile app page tree — purpose-built screens for the Capacitor-wrapped app (see
 * capacitor.config.ts), served as regular Inertia pages over the same session auth as the
 * web app. Login is a distinct screen but submits to Fortify's own POST /login; there's no
 * separate mobile auth mechanism.
 */
Route::get('/app/login', [\App\Http\Controllers\Mobile\LoginController::class, 'create'])
    ->name('mobile.login');

Route::middleware(['auth'])->prefix('app')->name('mobile.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Mobile\DashboardController::class, 'index'])
        ->name('dashboard');

    Route::get('/record', [\App\Http\Controllers\Mobile\RecordController::class, 'index'])
        ->name('record.index');

    Route::get('/organizations', [\App\Http\Controllers\Mobile\OrganizationController::class, 'index'])
        ->name('organizations.index');
    Route::post('/organizations', [\App\Http\Controllers\Mobile\OrganizationController::class, 'store'])
        ->name('organizations.store');

    Route::middleware(['client.access'])->group(function () {
        Route::get('/projects/{project}', [\App\Http\Controllers\Mobile\ProjectController::class, 'show'])
            ->name('projects.show');
        Route::get('/projects/{project}/notes/{document}', [\App\Http\Controllers\Mobile\NoteController::class, 'show'])
            ->name('notes.show');
        Route::get('/projects/{project}/documents/{document}', [\App\Http\Controllers\Mobile\DocumentController::class, 'show'])
            ->name('documents.show');
        Route::get('/record/{project}', [\App\Http\Controllers\Mobile\RecordController::class, 'show'])
            ->name('record.show');
        Route::post('/record/{project}', [\App\Http\Controllers\Mobile\RecordController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('record.store');
        Route::get('/record/{project}/status/{document}', [\App\Http\Controllers\Mobile\RecordController::class, 'status'])
            ->name('record.status');
    });
});

require __DIR__.'/settings.php';

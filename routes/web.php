<?php

use App\Http\Controllers\AiTemplateController;
use App\Http\Controllers\BugReportController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MeetingTranscriptController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationLoginController;
use App\Http\Controllers\OrganizationRegistrationController;
use App\Http\Controllers\OrganizationSetupController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectTypeController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

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

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
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

/**
 * Access Pending:
 * A fallback page for users who are logged in but not yet assigned
 * to an organization or lack a global role.
 */
Route::get('access-pending', function () {
    return Inertia::render('Dashboard/AccessPending');
})->middleware(['auth', 'verified'])->name('dashboard.pending');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/organization/setup', [OrganizationSetupController::class, 'create'])->name('organization.setup');
    Route::post('/organization/setup', [OrganizationSetupController::class, 'store'])->name('organization.setup.store');

    /**
     * 1. Management & Admin Area
     * We allow both Global Super Admins and Organization Admins here.
     * Note: Use the pipe '|' to allow multiple roles in Spatie's middleware.
     */
    Route::post('/organizations/{organization}/users', [OrganizationController::class, 'addUser'])
        ->name('organizations.users.store');

    Route::middleware(['role:super-admin'])->group(function () {
        Route::get('/users/list', [UserController::class, 'list'])
            ->name('users.list');
        Route::post('/users/{user}/promote', [UserController::class, 'promote'])
            ->name('users.promote');
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
        Route::resource('ai-templates', AiTemplateController::class);
        Route::resource('tasks', TaskController::class);
    });

    // Main Entry Point
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Organizations are accessible to any org member; policy handles per-action authorization.
    Route::resource('organizations', OrganizationController::class);
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
    Route::middleware(['client.access'])->group(function () {
        Route::resource('clients', ClientController::class);
        Route::resource('comments', CommentController::class);
        Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
        Route::resource('projects', ProjectController::class);

        Route::post('/projects/{project}/generate', [ProjectController::class, 'generate'])
            ->name('projects.generate');

        Route::patch('/projects/{project}/lifecycle-step', [ProjectController::class, 'updateLifecycleStep'])
            ->name('projects.lifecycle-step');

        Route::patch('/projects/{project}/reactivate', [ProjectController::class, 'reactivate'])
            ->name('projects.reactivate');

        // 3. Project Documents & Transcripts
        Route::prefix('projects/{project}')->name('projects.')->group(function () {
            Route::match(['get', 'post'], '/documents/search', [DocumentController::class, 'search'])
                ->middleware('throttle:30,1')
                ->name('documents.search');
            Route::post('/documents/{document}/reprocess', [DocumentController::class, 'reprocess'])
                ->middleware('throttle:10,1')
                ->name('documents.reprocess');
            Route::patch('/documents/{document}/attributes', [DocumentController::class, 'updateAttributes'])
                ->name('documents.updateAttributes');

            Route::resource('documents', DocumentController::class);

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

require __DIR__.'/settings.php';

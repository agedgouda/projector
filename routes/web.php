<?php

use App\Http\Controllers\AiTemplateController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectTypeController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

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

    /**
     * 1. Management & Admin Area
     * We allow both Global Super Admins and Organization Admins here.
     * Note: Use the pipe '|' to allow multiple roles in Spatie's middleware.
     */
    Route::middleware(['role:super-admin|org-admin'])->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');

        Route::resource('roles', RoleController::class);
        Route::delete('/roles/{role}/users/{user}', [RoleController::class, 'unassignUser'])
            ->name('roles.users.destroy');

        Route::resource('project-types', ProjectTypeController::class);
        Route::resource('ai-templates', AiTemplateController::class);
        Route::resource('tasks', TaskController::class);
    });

    // Main Entry Point
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /**
     * 2. Client & Project Management
     * This uses your updated 'EnsureUserCanAccessClient' middleware (aliased as client.access)
     */
    Route::middleware(['client.access'])->group(function () {
        Route::resource('clients', ClientController::class);
        Route::resource('comments', CommentController::class);
        Route::resource('projects', ProjectController::class);

        Route::resource('organizations', OrganizationController::class);

        Route::post('/projects/{project}/generate', [ProjectController::class, 'generate'])
            ->name('projects.generate');

        // 3. Project Documents
        Route::prefix('projects/{project}')->name('projects.')->group(function () {
            Route::match(['get', 'post'], '/documents/search', [DocumentController::class, 'search'])
                ->middleware('throttle:30,1')
                ->name('documents.search');
            Route::post('/documents/{document}/reprocess', [DocumentController::class, 'reprocess'])
                ->middleware('throttle:10,1')
                ->name('documents.reprocess');

            Route::resource('documents', DocumentController::class);
        });
    });
});

require __DIR__.'/settings.php';

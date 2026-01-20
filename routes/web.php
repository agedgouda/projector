<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectTypeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\AiTemplateController;


use App\Events\DocumentProcessingUpdate;
use App\Models\Document;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('dashboard', function () {
    event(new \App\Events\TestEvent());
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {

    // 1. Admin ONLY
    Route::middleware(['role:admin'])->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::resource('roles', RoleController::class);
        Route::delete('/roles/{role}/users/{user}', [RoleController::class, 'unassignUser'])
            ->name('roles.users.destroy');
        Route::resource('project-types', ProjectTypeController::class);
        Route::resource('ai-templates', AiTemplateController::class);
        Route::resource('tasks', TaskController::class);
    });

    // 2. Client & Project Management (PROTECTED BY MIDDLEWARE)
    // We apply the middleware to these groups
    Route::middleware(['client.access'])->group(function () {

        Route::resource('clients', ClientController::class);
        Route::resource('comments', CommentController::class);
        // This keeps the name 'projects.index', so Wayfinder is happy
        Route::resource('projects', ProjectController::class);

        Route::post('/projects/{project}/generate', [ProjectController::class, 'generate'])
            ->name('projects.generate');

        // 3. Project Documents (Using the existing prefix)
        Route::prefix('projects/{project}')->name('projects.')->group(function () {
            Route::match(['get', 'post'], '/documents/search', [DocumentController::class, 'search'])
                ->name('documents.search');
            Route::post('/documents/{document}/reprocess', [DocumentController::class, 'reprocess'])
                ->name('documents.reprocess');

            Route::resource('documents', DocumentController::class)
                ->only(['store', 'update', 'destroy']);
        });
    });
});


require __DIR__.'/settings.php';

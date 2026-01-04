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

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {

    // 1. Admin Management Section
    Route::middleware(['role:admin'])->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users/{user}/roles', [UserController::class, 'updateRole'])->name('users.roles.update');

        Route::resource('roles', RoleController::class);
        Route::resource('project-types', ProjectTypeController::class);
    });

    // 2. Client Management
    Route::resource('clients', ClientController::class);

    // 3. Project & Sub-Resource Management
    // Define specific project routes before the resource to ensure correct matching
    Route::post('/projects/{project}/generate', [ProjectController::class, 'generate'])->name('projects.generate');
    Route::resource('projects', ProjectController::class);

    // 4. Project Documents (Nested Prefix Group)
    Route::prefix('projects/{project}')->name('projects.')->group(function () {

        // Document Search & Reprocess
        Route::match(['get', 'post'], '/documents/search', [DocumentController::class, 'search'])
            ->name('documents.search');
        Route::post('/documents/{document}/reprocess', [DocumentController::class, 'reprocess'])
            ->name('documents.reprocess');

        // Document CRUD
        Route::resource('documents', DocumentController::class)
            ->only(['store', 'update', 'destroy']);
    });
});


require __DIR__.'/settings.php';

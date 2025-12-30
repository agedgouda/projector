<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectTypeController;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {

    Route::prefix('projects/{project}')->name('projects.')->group(function () {
        // This handles the AI generation process
        Route::post('/generate', [ProjectController::class, 'generate'])
            ->name('generate');

        // Your existing document routes
        Route::resource('documents', DocumentController::class)
            ->only(['store', 'update', 'destroy']);

        Route::match(['get', 'post'], '/documents/search', [DocumentController::class, 'search'])
            ->name('documents.search');

        Route::post('/documents/{document}/reprocess', [DocumentController::class, 'reprocess'])
            ->name('documents.reprocess');
    });

    Route::resource('clients', ClientController::class);
    Route::resource('projects', ProjectController::class);
    Route::post('/projects/{project}/generate', [ProjectController::class, 'generate'])->name('projects.generate');
    Route::resource('project-types', ProjectTypeController::class);

});


require __DIR__.'/settings.php';

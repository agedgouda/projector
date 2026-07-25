<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\RecordingController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('api.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('api.logout');

    Route::get('/projects', [ProjectController::class, 'index'])->name('api.projects.index');

    Route::get('/recordings', [RecordingController::class, 'index'])->name('api.recordings.index');
    Route::get('/recordings/{document}', [RecordingController::class, 'show'])->name('api.recordings.show');
    Route::post('/recordings/{document}/confirm', [RecordingController::class, 'confirm'])->name('api.recordings.confirm');
    Route::post('/projects/{project}/recordings', [RecordingController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('api.projects.recordings.store');
});

<?php

use App\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

// The public demo, embedded on the marketing site (about.projecthq.app) in an iframe — see
// AllowDemoFraming. No auth: it never reads or writes any account's data.
Route::middleware('demo.framing')->group(function () {
    Route::get('/demo', [DemoController::class, 'show'])->name('demo.show');
    Route::post('/demo/extract', [DemoController::class, 'extract'])
        ->middleware('throttle:demo')
        ->name('demo.extract');
});

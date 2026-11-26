<?php

use App\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

// The public demo, linked from the marketing site (about.projecthq.app). Starting it creates a
// temporary sandbox account (see DemoSandbox) and signs the visitor in to it.
Route::get('/demo', [DemoController::class, 'show'])->name('demo.show');
Route::post('/demo', [DemoController::class, 'start'])
    ->middleware('throttle:demo-start')
    ->name('demo.start');
// The page posts the email from an Apollo link's #email= fragment here (see DemoProspect), so
// the address never appears in a URL the server or its access logs see.
Route::post('/demo/prospect', [DemoController::class, 'identify'])
    ->middleware('throttle:20,1')
    ->name('demo.prospect');
Route::post('/demo/exit', [DemoController::class, 'exit'])->name('demo.exit');

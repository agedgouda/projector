<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:prune-unapproved-recordings')->daily();

// Hourly, not daily — see SendSlackDailyDigest's own docblock for why: there's no single UTC
// time that's 8am in every admin's own timezone, so the command itself checks each admin's
// local hour every time this runs.
Schedule::command('app:send-slack-daily-digest')->hourly();

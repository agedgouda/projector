<?php

use Illuminate\Queue\Events\JobProcessing;

/**
 * Covers AppServiceProvider::boot()'s JobProcessing listener — the fix for Slack messages,
 * digests, etc. (built via route()/url() with no bound request) resolving to whatever host a
 * long-lived worker happened to boot with, instead of the current APP_URL. Deliberately scoped
 * to JobProcessing rather than forced unconditionally in boot(), since this app serves real web
 * traffic through Octane, which — like Horizon — is a long-lived CLI process: forcing the root
 * URL unconditionally previously broke asset/page URL generation for every real visitor once
 * APP_URL didn't exactly match how the app is actually reached.
 */
it('re-forces the root URL from the current APP_URL every time a queued job starts processing', function () {
    // Simulates a worker that booted a while ago with a since-changed APP_URL — exactly the
    // scenario the original bug report was about.
    Illuminate\Support\Facades\URL::forceRootUrl('http://stale-worker-boot-host.test');
    expect(url('/foo'))->toStartWith('http://stale-worker-boot-host.test');

    config(['app.url' => 'http://projecthq.app']);
    expect(url('/foo'))
        ->toStartWith('http://stale-worker-boot-host.test');

    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('payload')->andReturn([]);

    event(new JobProcessing('redis', $job));

    expect(url('/foo'))->toStartWith('http://projecthq.app');
});

<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;

it('accepts a stale asset chunk report without requiring auth', function () {
    Log::spy();

    $response = $this->postJson('/client-logs/stale-asset', [
        'chunk' => 'Documents/Create',
        'message' => 'Failed to fetch dynamically imported module',
        'page_url' => 'https://projecthq.app/projects/1?tab=calendar',
        'client_version' => 'old-hash',
    ]);

    $response->assertNoContent();

    Log::shouldNotHaveReceived('warning');
});

it('accepts a stale asset chunk report from an authenticated user', function () {
    Log::spy();

    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/client-logs/stale-asset', [
        'chunk' => 'Documents/Create',
    ])->assertNoContent();

    Log::shouldNotHaveReceived('warning');
});

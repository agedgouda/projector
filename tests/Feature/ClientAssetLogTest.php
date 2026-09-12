<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;

it('logs a stale asset chunk report without requiring auth', function () {
    Log::spy();

    $response = $this->postJson('/client-logs/stale-asset', [
        'chunk' => 'Documents/Create',
        'message' => 'Failed to fetch dynamically imported module',
        'page_url' => 'https://projecthq.app/projects/1?tab=calendar',
        'client_version' => 'old-hash',
    ]);

    $response->assertNoContent();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'Stale asset chunk failed to load'
            && $context['chunk'] === 'Documents/Create'
            && $context['client_version'] === 'old-hash'
            && array_key_exists('server_version', $context)
            && $context['user_id'] === null);
});

it('logs the authenticated user id when available', function () {
    Log::spy();

    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/client-logs/stale-asset', [
        'chunk' => 'Documents/Create',
    ])->assertNoContent();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $context['user_id'] === $user->id);
});

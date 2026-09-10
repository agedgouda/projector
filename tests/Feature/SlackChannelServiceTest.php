<?php

use App\Models\SlackWorkspace;
use App\Services\Slack\SlackChannelService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = SlackWorkspace::factory()->create();
    $this->service = app(SlackChannelService::class);
});

// ── joinChannel() ──────────────────────────────────────────────────────────────

it('returns true when the bot successfully joins the channel', function () {
    Http::fake(['slack.com/api/conversations.join' => Http::response(['ok' => true], 200)]);

    expect($this->service->joinChannel($this->workspace, 'C1'))->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/conversations.join'
        && $request['channel'] === 'C1');
});

it('returns false and logs a warning, without throwing, when slack rejects the join', function () {
    Http::fake(['slack.com/api/conversations.join' => Http::response(['ok' => false, 'error' => 'method_not_supported_for_channel_type'], 200)]);

    Log::shouldReceive('warning')->once()->with(
        'Failed to auto-join a Slack channel after binding it',
        Mockery::on(fn (array $context) => $context['channel'] === 'C1' && $context['error'] === 'method_not_supported_for_channel_type')
    );

    expect($this->service->joinChannel($this->workspace, 'C1'))->toBeFalse();
});

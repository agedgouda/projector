<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config(['services.slack.signing_secret' => 'fake-signing-secret']);
});

function signSlackRequest(string $body, ?int $timestamp = null): array
{
    $timestamp ??= time();
    $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'fake-signing-secret');

    return [
        'X-Slack-Request-Timestamp' => (string) $timestamp,
        'X-Slack-Signature' => $signature,
    ];
}

it('answers the url_verification handshake when correctly signed', function () {
    $payload = ['type' => 'url_verification', 'challenge' => 'abc123'];
    $body = json_encode($payload);

    $this->withHeaders(signSlackRequest($body))
        ->postJson('/slack/events', $payload)
        ->assertOk()
        ->assertJson(['challenge' => 'abc123']);
});

it('rejects a request with an invalid signature', function () {
    $payload = ['type' => 'url_verification', 'challenge' => 'abc123'];

    $this->withHeaders([
        'X-Slack-Request-Timestamp' => (string) time(),
        'X-Slack-Signature' => 'v0=not-a-real-signature',
    ])
        ->postJson('/slack/events', $payload)
        ->assertUnauthorized();
});

it('rejects a request missing the signature headers', function () {
    $this->postJson('/slack/events', ['type' => 'url_verification', 'challenge' => 'abc123'])
        ->assertUnauthorized();
});

it('rejects a stale request even with a valid signature', function () {
    $payload = ['type' => 'url_verification', 'challenge' => 'abc123'];
    $body = json_encode($payload);
    $staleTimestamp = time() - 600;

    $this->withHeaders(signSlackRequest($body, $staleTimestamp))
        ->postJson('/slack/events', $payload)
        ->assertUnauthorized();
});

it('acknowledges an unhandled event type with a 204', function () {
    $payload = ['type' => 'event_callback', 'event' => ['type' => 'message']];
    $body = json_encode($payload);

    $this->withHeaders(signSlackRequest($body))
        ->postJson('/slack/events', $payload)
        ->assertNoContent();
});

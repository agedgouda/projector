<?php

use App\Rules\TurnstileChallenge;
use Illuminate\Support\Facades\Http;

it('passes silently when no secret key is configured', function () {
    config(['services.turnstile.secret_key' => null]);

    $failed = false;
    (new TurnstileChallenge('127.0.0.1'))->validate('cf-turnstile-response', 'any-token', function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeFalse();
});

it('fails when the response token is missing but a secret key is configured', function () {
    config(['services.turnstile.secret_key' => 'test-secret']);

    $failed = false;
    (new TurnstileChallenge('127.0.0.1'))->validate('cf-turnstile-response', '', function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeTrue();
});

it('passes when Cloudflare confirms the token is valid', function () {
    config(['services.turnstile.secret_key' => 'test-secret']);
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true]),
    ]);

    $failed = false;
    (new TurnstileChallenge('127.0.0.1'))->validate('cf-turnstile-response', 'valid-token', function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeFalse();
});

it('fails when Cloudflare rejects the token', function () {
    config(['services.turnstile.secret_key' => 'test-secret']);
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]),
    ]);

    $failed = false;
    (new TurnstileChallenge('127.0.0.1'))->validate('cf-turnstile-response', 'bad-token', function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeTrue();
});

<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->apolloKey = Str::random(32);
    config(['services.apollo.key' => $this->apolloKey]);
});

it('reports a working key and sends it in the x-api-key header', function () {
    Http::fake(['api.apollo.io/api/v1/auth/health' => Http::response(['healthy' => true, 'is_logged_in' => true])]);

    $this->artisan('apollo:check')
        ->expectsOutput('Connected to Apollo.io: the API key is valid.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->hasHeader('x-api-key', $this->apolloKey)
        && ! $request->hasHeader('Authorization'));
});

it('fails when Apollo rejects the key', function () {
    Http::fake(['api.apollo.io/*' => Http::response(['error' => 'Invalid access credentials.'], 401)]);

    $this->artisan('apollo:check')->assertFailed();
});

it('fails when the key is answered but not signed in', function () {
    Http::fake(['api.apollo.io/*' => Http::response(['healthy' => true, 'is_logged_in' => false])]);

    $this->artisan('apollo:check')->assertFailed();
});

it('fails without calling Apollo when no key is set', function () {
    config(['services.apollo.key' => null]);
    Http::fake();

    $this->artisan('apollo:check')
        ->expectsOutput('APOLLO_API_KEY is not set.')
        ->assertFailed();

    Http::assertNothingSent();
});

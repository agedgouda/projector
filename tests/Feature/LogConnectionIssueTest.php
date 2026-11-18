<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects guests attempting to log a connection issue', function () {
    $this->post('/log-connection-issue', ['state' => 'disconnected'])
        ->assertRedirectContains('login');
});

it('allows an authenticated user to log a connection issue', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/log-connection-issue', ['state' => 'disconnected'])
        ->assertSuccessful()
        ->assertJson(['status' => 'logged']);
});

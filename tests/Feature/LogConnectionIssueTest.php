<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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

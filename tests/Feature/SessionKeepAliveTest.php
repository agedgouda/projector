<?php

use App\Models\Organization;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns no content for an authenticated user pinging the keep-alive route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/session/keep-alive')
        ->assertNoContent();
});

it('redirects a guest hitting the keep-alive route to login', function () {
    $this->post('/session/keep-alive')
        ->assertRedirect('/login?expired=1');
});

it('shares the configured session lifetime with every authenticated Inertia page', function () {
    $user = User::factory()->create();
    $org = Organization::create(['name' => 'Test Org']);
    $org->users()->attach($user->id, ['role' => 'org-admin']);

    config(['session.lifetime' => 45]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('session.lifetime_minutes', 45)
        );
});

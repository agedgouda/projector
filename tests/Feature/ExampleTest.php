<?php

it('redirects guests to the marketing site', function () {
    $response = $this->get('/');

    $response->assertRedirect('https://about.projecthq.app/');
});

it('redirects authenticated users to the dashboard', function () {
    $user = \App\Models\User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/');

    $response->assertRedirect(route('dashboard'));
});

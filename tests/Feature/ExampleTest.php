<?php

it('redirects guests to the marketing site on any host other than the local Herd domain', function () {
    $response = $this->get('https://projecthq.app/');

    $response->assertRedirect('https://about.projecthq.app/');
});

it('redirects guests straight to the login form on the local Herd domain, which has no marketing site of its own', function () {
    $response = $this->get('http://projector.test/');

    $response->assertRedirect('http://projector.test/login');
});

it('redirects authenticated users to the dashboard', function () {
    $user = \App\Models\User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/');

    $response->assertRedirect(route('dashboard'));
});

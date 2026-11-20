<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('points the register page at its canonical URL', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('<link rel="canonical" href="https://projecthq.app/register" />', false);
});

it('points the login page at its canonical URL', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<link rel="canonical" href="https://projecthq.app/login" />', false);
});

it('adds no canonical link to other pages', function () {
    $this->get(route('password.request'))
        ->assertOk()
        ->assertDontSee('rel="canonical"', false);
});

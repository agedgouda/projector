<?php

it('serves the terms of service to guests as server-rendered html', function () {
    $this->get(route('terms'))
        ->assertOk()
        ->assertSee('Terms of Service')
        ->assertSee('Effective date: October 15, 2026')
        ->assertSee('1. Agreement to these terms')
        ->assertSee('21. Contact us')
        ->assertSee('privacy@kaufmaninternational.com')
        ->assertSee('1418 N. Brighton Street, Burbank, CA 91506');
});

it('states the business terms the operator chose', function () {
    $this->get(route('terms'))
        ->assertOk()
        ->assertSee('You must be at least 18 years old')
        ->assertSee('free trial of six weeks. No credit card is required')
        ->assertSee('We will not charge you without that.')
        ->assertSee('we do not guarantee any level of uptime')
        ->assertSee('there is no service level agreement')
        ->assertSee('FEES YOU PAID US FOR THE SERVICE IN THE 12 MONTHS BEFORE THE')
        ->assertSee('governed by the laws of the State of California')
        ->assertSee('state or federal courts located in California')
        ->assertDontSee('arbitration')
        ->assertDontSee('class action');
});

it('states the data export and deletion timelines', function () {
    $this->get(route('terms'))
        ->assertOk()
        ->assertSee('we will let you access your data for 30 days')
        ->assertSee('within 60 days after your trial or subscription ends')
        ->assertSee('generally within 90 days')
        ->assertSee('keep billing records, or resolve disputes');
});

it('makes users responsible for recording consent and reviewing ai output', function () {
    $this->get(route('terms'))
        ->assertOk()
        ->assertSee('obtaining any consent that the law requires from every meeting')
        ->assertSee('Recordings that are not approved in Projector are deleted after 10 days.')
        ->assertSee('You are responsible for reviewing')
        ->assertSee('generated content before relying on it');
});

it('links the terms from the privacy consent at registration and the about page', function () {
    expect(file_get_contents(resource_path('js/components/PrivacyPolicyConsent.vue')))
        ->toContain('terms.url()')
        ->toContain('Terms of Service');

    $this->get(route('about'))->assertOk()->assertSee('href="/terms"', false);
});

it('advertises the same trial length on the sign-up page as the terms', function () {
    expect(file_get_contents(resource_path('js/pages/auth/Register.vue')))
        ->toContain('Free 6-week trial')
        ->not->toContain('14-day');
});

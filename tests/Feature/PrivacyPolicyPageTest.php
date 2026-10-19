<?php

it('serves the privacy policy to guests as server-rendered html', function () {
    $this->get(route('privacy'))
        ->assertOk()
        ->assertSee('Effective date: October 19, 2026')
        ->assertSee('KI Inc is the business responsible for this policy')
        ->assertSee('1. Introduction')
        ->assertSee('17. Contact us')
        ->assertSee('privacy@kaufmaninternational.com');
});

it('keeps the google drive.file disclosure required for oauth verification', function () {
    $this->get(route('privacy'))
        ->assertOk()
        ->assertSee('8. Google account connection')
        ->assertSee('https://www.googleapis.com/auth/drive.file');
});

it('describes the ai providers and processing the app actually uses', function () {
    $this->get(route('privacy'))
        ->assertOk()
        ->assertSee('By default this is OpenAI.')
        ->assertSee('approved are deleted after 10 days.')
        ->assertSee('their own OpenAI or Google Gemini account')
        ->assertDontSee('instead choose Google Gemini')
        ->assertSee('Meeting audio is sent to AssemblyAI for transcription.')
        ->assertSee('users can review and edit them')
        ->assertSee('By creating an account, users agree to the AI processing described in this section.')
        ->assertDontSee('vectorize data')
        ->assertDontSee('receive AI-generated content from it')
        ->assertDontSee('asks for the user\'s permission before personal data is shared');
});

it('has no leftover typos from the source document', function () {
    $this->get(route('privacy'))
        ->assertOk()
        ->assertDontSee('childrens')
        ->assertDontSee('saved..')
        ->assertDontSee('OpenAI, Gemini:');
});

it('describes the information collected and the providers it is shared with', function () {
    $this->get(route('privacy'))
        ->assertOk()
        ->assertSee('Projector collects the following information to provide the service:')
        ->assertSee('This record does not include an IP address and is not linked to an account.')
        ->assertSee('Projector does not use third-party')
        ->assertSee('The operator shares personal data only with service providers')
        ->assertSee('DigitalOcean (hosting and infrastructure)')
        ->assertSee('Resend
        (email delivery)', false)
        ->assertDontSee('Mailgun')
        ->assertDontSee('does not intentionally collect personal information')
        ->assertDontSee('as follows: none are shared')
        ->assertDontSee('analytics, advertising,
        social, or service-provider sections', false);
});

it('discloses email open tracking, no click tracking, and limits its use', function () {
    $this->get(route('privacy'))
        ->assertOk()
        ->assertSee('the service sends include open tracking')
        ->assertSee('and whether it was opened, with the time of each.')
        ->assertSee('not tracked.')
        ->assertDontSee('click tracking')
        ->assertSee('for marketing, advertising, or profiling.')
        ->assertDontSee('does not measure message opens');
});

it('discloses what the public demo sends to the ai provider and keeps', function () {
    $this->get(route('privacy'))
        ->assertOk()
        ->assertSee('The public demo page lets anyone, without an account')
        ->assertSee('Projector does not save the text or the result')
        ->assertSee('check sign-ups and demo runs for bots');
});

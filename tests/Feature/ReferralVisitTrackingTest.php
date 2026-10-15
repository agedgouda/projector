<?php

use App\Models\ReferralVisit;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

const CHROME_DESKTOP_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
const SAFARI_IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';

it('records a referral visit and redirects without the ref param', function (string $routeName, string $path) {
    $response = $this->withHeaders([
        'User-Agent' => CHROME_DESKTOP_UA,
        'CF-IPCountry' => 'DE',
        'Referer' => 'https://www.example-marketing.com/pricing?email=someone@example.com',
    ])->get(route($routeName, ['ref' => 'fall-campaign']));

    $response->assertRedirect(route($routeName));

    $visit = ReferralVisit::sole();

    expect($visit->ref)->toBe('fall-campaign')
        ->and($visit->landing_path)->toBe($path)
        ->and($visit->country)->toBe('DE')
        ->and($visit->device_type)->toBe('desktop')
        ->and($visit->browser)->toBe('Chrome')
        ->and($visit->referrer_host)->toBe('www.example-marketing.com')
        ->and($visit->converted_at)->toBeNull();

    $response->assertSessionHas(ReferralVisit::SESSION_KEY, $visit->id);
})->with([
    'login' => ['login', '/login'],
    'register' => ['register', '/register'],
]);

it('stores no personal data', function () {
    $this->withHeaders(['User-Agent' => SAFARI_IPHONE_UA])
        ->get(route('login', ['ref' => 'footer']));

    expect(array_keys(ReferralVisit::sole()->getAttributes()))->toEqualCanonicalizing([
        'id', 'ref', 'landing_path', 'country', 'device_type', 'browser',
        'referrer_host', 'converted_at', 'created_at', 'updated_at',
    ])->and(ReferralVisit::sole()->device_type)->toBe('smartphone')
        ->and(ReferralVisit::sole()->browser)->toBe('Mobile Safari');
});

it('keeps other query params when stripping ref', function () {
    $this->withHeaders(['User-Agent' => CHROME_DESKTOP_UA])
        ->get('/register?ref=hero&plan=pro')
        ->assertRedirect(route('register', ['plan' => 'pro']));
});

it('records only once per session', function () {
    $this->withHeaders(['User-Agent' => CHROME_DESKTOP_UA])->get(route('login', ['ref' => 'first']));
    $this->withHeaders(['User-Agent' => CHROME_DESKTOP_UA])
        ->get(route('register', ['ref' => 'second']))
        ->assertRedirect(route('register'));

    expect(ReferralVisit::sole()->ref)->toBe('first');
});

it('does not record logged-in users but still strips the ref', function () {
    $this->actingAs(User::factory()->create())
        ->withHeaders(['User-Agent' => CHROME_DESKTOP_UA])
        ->get(route('login', ['ref' => 'campaign']))
        ->assertRedirect(route('login'));

    expect(ReferralVisit::count())->toBe(0);
});

it('does not record bots', function (string $userAgent) {
    $this->withHeaders(['User-Agent' => $userAgent])
        ->get(route('login', ['ref' => 'campaign']))
        ->assertRedirect(route('login'));

    expect(ReferralVisit::count())->toBe(0);
})->with([
    'slack' => 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)',
    'linkedin' => 'LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)',
    'facebook' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
    'googlebot' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'curl' => 'curl/8.4.0',
    'empty' => '',
]);

it('ignores a blank or array ref', function (string $query) {
    $this->withHeaders(['User-Agent' => CHROME_DESKTOP_UA])
        ->get('/login?'.$query)
        ->assertRedirect(route('login'));

    expect(ReferralVisit::count())->toBe(0);
})->with([
    'blank' => 'ref=',
    'whitespace' => 'ref=%20%20',
    'array' => 'ref[]=a',
]);

it('truncates an overlong ref', function () {
    $this->withHeaders(['User-Agent' => CHROME_DESKTOP_UA])
        ->get(route('login', ['ref' => str_repeat('a', 400)]));

    expect(mb_strlen(ReferralVisit::sole()->ref))->toBe(255);
});

it('stores null for a missing or malformed country and referrer', function () {
    $this->withHeaders(['User-Agent' => CHROME_DESKTOP_UA, 'CF-IPCountry' => 'not-a-country', 'Referer' => 'not a url'])
        ->get(route('login', ['ref' => 'campaign']));

    expect(ReferralVisit::sole()->country)->toBeNull()
        ->and(ReferralVisit::sole()->referrer_host)->toBeNull();
});

it('leaves requests without ref and other routes untouched', function () {
    $this->get(route('login'))->assertOk();
    $this->get('/faq?ref=campaign')->assertDontSee('referral_visits');

    expect(ReferralVisit::count())->toBe(0);
});

it('marks the visit converted on registration without linking the user', function () {
    $this->withHeaders(['User-Agent' => CHROME_DESKTOP_UA])->get(route('register', ['ref' => 'campaign']));

    $this->post(route('register.store'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'privacy_policy' => '1',
    ])->assertSessionMissing(ReferralVisit::SESSION_KEY);

    $this->assertAuthenticated();
    expect(ReferralVisit::sole()->converted_at)->not->toBeNull();
});

it('does not mark anything converted when registering without a referral', function () {
    $visit = ReferralVisit::factory()->create();

    $this->post(route('register.store'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'privacy_policy' => '1',
    ]);

    $this->assertAuthenticated();
    expect($visit->fresh()->converted_at)->toBeNull();
});

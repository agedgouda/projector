<?php

use App\Models\DemoEvent;
use App\Models\DemoSession;
use App\Models\ReferralVisit;
use App\Models\User;
use App\Services\Demo\DemoSandbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const DEMO_VISITOR_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15';

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();

    config([
        'services.turnstile.secret_key' => null,
        'services.demo.daily_sandbox_limit' => 100,
    ]);

    $this->withHeaders(['User-Agent' => DEMO_VISITOR_UA]);
});

function landOnDemo(array $query = []): DemoSession
{
    test()->get(route('demo.show', $query))->assertOk();

    return DemoSession::sole();
}

function registerAfterDemo(): void
{
    $password = Str::password(16);

    test()->post(route('register.store'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => Str::random(10).'@example.com',
        'password' => $password,
        'password_confirmation' => $password,
        'privacy_policy' => '1',
    ]);

    test()->assertAuthenticated();
}

it('records a guest landing on the demo page with its campaign parameters and device', function () {
    $session = landOnDemo([
        'ref' => 'home.hero-1',
        'utm_source' => 'newsletter',
        'utm_medium' => 'email',
        'utm_campaign' => 'november',
    ]);

    expect($session->ref)->toBe('home.hero-1')
        ->and($session->utm_source)->toBe('newsletter')
        ->and($session->utm_medium)->toBe('email')
        ->and($session->utm_campaign)->toBe('november')
        ->and($session->device_type)->toBe('desktop')
        ->and($session->browser)->toBe('Safari')
        ->and($session->started_at)->toBeNull();
});

it('records a landing only once per browser session', function () {
    $this->get(route('demo.show'));
    $this->get(route('demo.show', ['ref' => 'second']));

    expect(DemoSession::count())->toBe(1)
        ->and(DemoSession::sole()->ref)->toBeNull();
});

it('does not record bots or signed-in users', function () {
    $this->withHeaders(['User-Agent' => 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)'])
        ->get(route('demo.show'))
        ->assertOk();

    $this->withHeaders(['User-Agent' => DEMO_VISITOR_UA])
        ->actingAs(User::factory()->create())
        ->get(route('demo.show'))
        ->assertOk();

    expect(DemoSession::count())->toBe(0);
});

it('links the landing to an earlier referral visit from the same browser session', function () {
    $this->get(route('register', ['ref' => 'footer']));

    expect(landOnDemo()->referral_visit_id)->toBe(ReferralVisit::sole()->id);
});

it('records when the visitor starts the demo', function () {
    landOnDemo();

    $this->post(route('demo.start'))->assertRedirect();

    expect(DemoSession::sole()->started_at)->not->toBeNull();
});

it('records a visitor turned away because the demo is at capacity', function () {
    config(['services.demo.daily_sandbox_limit' => 0]);
    landOnDemo();

    $this->post(route('demo.start'))->assertSessionHasErrors('demo');

    $session = DemoSession::sole();
    expect($session->rejected_reason)->toBe(DemoSession::REJECTED_BUSY)
        ->and($session->rejected_at)->not->toBeNull()
        ->and($session->started_at)->toBeNull();
});

it('records a visitor turned away by the verification check', function () {
    config(['services.turnstile.secret_key' => Str::random(32)]);
    Http::fake();
    landOnDemo();

    $this->post(route('demo.start'))->assertSessionHasErrors('cf-turnstile-response');

    expect(DemoSession::sole()->rejected_reason)->toBe(DemoSession::REJECTED_VERIFICATION);
});

it('logs pages the demo visitor opens and actions they take, but not background requests', function () {
    landOnDemo();
    $this->post(route('demo.start'));

    $project = auth()->user()->favoriteProjects()->orderBy('projects.name')->first();

    $this->get(route('projects.show', $project));
    $this->getJson(route('projects.show', $project));
    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Data' => 'documents'])
        ->get(route('projects.show', $project));

    $events = DemoEvent::query()->where('demo_session_id', DemoSession::sole()->id)->pluck('event', 'method');
    expect(DemoEvent::query()->where('event', 'projects.show')->count())->toBe(1)
        ->and($events->get('POST'))->toBe('demo.start');
});

it('does not log activity for regular users', function () {
    $this->actingAs(User::factory()->create())->get(route('demo.show'));

    expect(DemoEvent::count())->toBe(0);
});

it('records leaving the demo and still counts a registration afterwards as the conversion', function () {
    $this->get(route('register', ['ref' => 'footer']));
    landOnDemo();
    $this->post(route('demo.start'));

    $this->post(route('demo.exit'))->assertRedirect(route('register'));
    $this->assertGuest();

    registerAfterDemo();

    $session = DemoSession::sole();
    expect($session->exited_at)->not->toBeNull()
        ->and($session->registered_at)->not->toBeNull()
        ->and(ReferralVisit::sole()->converted_at)->not->toBeNull();
});

it('keeps demo sessions and their events after the sandbox is deleted', function () {
    landOnDemo();
    $this->post(route('demo.start'));
    $visitor = auth()->user();

    $visitor->forceFill(['demo_expires_at' => now()->subMinute()])->save();
    app(DemoSandbox::class)->pruneExpired();

    expect(User::find($visitor->id))->toBeNull()
        ->and(DemoSession::count())->toBe(1)
        ->and(DemoEvent::count())->toBeGreaterThan(0);
});

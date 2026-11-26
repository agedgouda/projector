<?php

use App\Models\User;
use App\Services\Demo\DemoProspect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

const PROSPECT_EMAIL = 'dana@acme.example';

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    RateLimiter::clear('demo-prospect:127.0.0.1');

    $this->apolloKey = Str::random(32);

    config([
        'services.apollo.key' => $this->apolloKey,
        'services.turnstile.secret_key' => null,
        'services.demo.daily_sandbox_limit' => 100,
    ]);
});

function fakeApolloContacts(array $contacts): void
{
    Http::fake(['api.apollo.io/api/v1/contacts/search' => Http::response(['contacts' => $contacts])]);
}

function arriveFromApolloLink(string $email, array $query = []): TestResponse
{
    $demoUrl = route('demo.show', $query);
    test()->get($demoUrl)->assertOk();

    return test()->from($demoUrl)->post(route('demo.prospect'), ['email' => $email]);
}

function apolloContact(array $overrides = []): array
{
    return [
        'id' => Str::random(24),
        'first_name' => 'Dana',
        'last_name' => 'Wells',
        'email' => PROSPECT_EMAIL,
        'organization_name' => 'Acme Corp',
        ...$overrides,
    ];
}

it('looks up the posted Apollo contact and personalizes the demo page', function () {
    fakeApolloContacts([apolloContact()]);

    arriveFromApolloLink(PROSPECT_EMAIL, ['ref' => 'apollo-q4-step1'])
        ->assertRedirect(route('demo.show', ['ref' => 'apollo-q4-step1']));

    Http::assertSent(fn (Request $request) => $request->hasHeader('x-api-key', $this->apolloKey)
        && $request['q_keywords'] === PROSPECT_EMAIL);

    $this->get(route('demo.show', ['ref' => 'apollo-q4-step1']))
        ->assertInertia(fn (Assert $page) => $page->component('Demo/Index')->where('company', 'Acme Corp'));
});

it('ignores an email in the query string, so it is never needed in a logged URL', function () {
    Http::fake();

    $this->get(route('demo.show', ['email' => PROSPECT_EMAIL]))
        ->assertInertia(fn (Assert $page) => $page->where('company', null));

    Http::assertNothingSent();
});

it('names the demo organization after the prospect\'s company', function () {
    fakeApolloContacts([apolloContact()]);
    arriveFromApolloLink(PROSPECT_EMAIL);

    $this->post(route('demo.start'))->assertRedirect();

    expect(auth()->user()->organizations()->value('name'))->toBe('Acme Corp');
});

it('pre-fills the sign-up form after the prospect leaves the demo', function () {
    fakeApolloContacts([apolloContact()]);
    arriveFromApolloLink(PROSPECT_EMAIL);
    $this->post(route('demo.start'));

    $this->post(route('demo.exit'))->assertRedirect(route('register'));

    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page
        ->component('auth/Register')
        ->where('prefill', ['first_name' => 'Dana', 'last_name' => 'Wells', 'email' => PROSPECT_EMAIL]));
});

it('shows the default demo when no contact has exactly that email', function () {
    fakeApolloContacts([apolloContact(['email' => 'someone-else@acme.example'])]);

    arriveFromApolloLink(PROSPECT_EMAIL)->assertRedirect(route('demo.show'));

    $this->get(route('demo.show'))->assertInertia(fn (Assert $page) => $page->where('company', null));
    $this->post(route('demo.start'));
    expect(auth()->user()->organizations()->value('name'))->toBe('Demo Agency');
});

it('shows the default demo when Apollo fails', function () {
    Http::fake(['api.apollo.io/*' => Http::response(['error' => 'Invalid access credentials.'], 401)]);

    arriveFromApolloLink(PROSPECT_EMAIL)->assertRedirect(route('demo.show'));

    expect(session(DemoProspect::SESSION_KEY))->toBeNull();
});

it('does not call Apollo for an invalid email, without an API key, or for a signed-in user', function (Closure $setUp, string $email) {
    Http::fake();
    $setUp();

    test()->from(route('demo.show'))->post(route('demo.prospect'), ['email' => $email])->assertRedirect(route('demo.show'));

    Http::assertNothingSent();
})->with([
    'invalid email' => [fn () => null, 'not-an-email'],
    'no API key' => [fn () => config(['services.apollo.key' => null]), PROSPECT_EMAIL],
    'signed-in user' => [fn () => test()->actingAs(User::factory()->create()), PROSPECT_EMAIL],
]);

it('stops looking up contacts after too many lookups from one visitor', function () {
    fakeApolloContacts([]);

    foreach (range(1, 11) as $attempt) {
        $this->from(route('demo.show'))->post(route('demo.prospect'), ['email' => "person{$attempt}@acme.example"]);
    }

    Http::assertSentCount(10);
});

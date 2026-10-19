<?php

use App\Http\Middleware\RestrictDemoUsers;
use App\Jobs\ProcessDocumentAI;
use App\Models\AiUsageLog;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\AiUsageLogger;
use App\Services\Demo\DemoSamples;
use App\Services\Demo\DemoSandbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();

    config([
        'services.turnstile.secret_key' => null,
        'services.demo.daily_sandbox_limit' => 100,
    ]);
});

function startDemo(): User
{
    test()->post(route('demo.start'))->assertRedirect();

    $user = auth()->user();
    expect($user)->toBeInstanceOf(User::class);

    return $user;
}

it('shows the demo start page to guests', function () {
    $this->get(route('demo.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Demo/Index')
            ->has('projects', count(DemoSamples::SAMPLES))
            ->where('isSignedIn', false)
            ->where('lifetimeHours', DemoSandbox::LIFETIME_HOURS));
});

it('creates a sandbox with three meeting transcripts and signs the visitor in', function () {
    $response = $this->post(route('demo.start'));

    $visitor = auth()->user();
    expect($visitor)->toBeInstanceOf(User::class)
        ->and($visitor->isDemo())->toBeTrue()
        ->and($visitor->email)->toEndWith('@'.DemoSandbox::EMAIL_DOMAIN);

    $organization = $visitor->organizations()->sole();
    expect($organization->membership_tier)->toBe(Organization::TIER_DEMO)
        ->and($organization->llm_driver)->toBe('openai')
        ->and($organization->llm_config)->toBe(['model' => 'gpt-4o-mini'])
        ->and($organization->pivot->role)->toBe('org-admin')
        ->and($organization->users()->count())->toBe(1 + 12);

    $projects = Project::whereHas('client', fn ($query) => $query->where('organization_id', $organization->id))->get();
    expect($projects)->toHaveCount(3)
        ->and($visitor->favoriteProjects()->count())->toBe(3);

    $transcripts = Document::whereIn('project_id', $projects->pluck('id'))->get();
    expect($transcripts)->toHaveCount(3)
        ->and($transcripts->pluck('type')->unique()->all())->toBe(['intake'])
        ->and($transcripts->first()->content)->toStartWith('<p>');

    // The real Transcription -> Meeting Notes run starts on its own for each transcript.
    Queue::assertPushed(ProcessDocumentAI::class, 3);

    $firstProject = $visitor->favoriteProjects()->orderBy('projects.name')->first();
    $response->assertRedirect(route('projects.show', ['project' => $firstProject->id, 'tab' => 'hierarchy']));
});

it('runs demo AI on the demo model with the system OpenAI key', function () {
    config(['services.openai.model' => 'gpt-5.2', 'services.openai.key' => 'system-key']);

    $organization = startDemo()->organizations()->sole();
    $organization->applyDriverConfig();

    expect(config('services.llm_driver'))->toBe('openai')
        ->and(config('services.openai.model'))->toBe('gpt-4o-mini')
        ->and(config('services.openai.key'))->toBe('system-key');
});

it('sends a demo visitor who comes back straight into their sandbox', function () {
    $visitor = startDemo();

    $this->get(route('demo.show'))->assertRedirectContains('/projects/');

    expect(auth()->id())->toBe($visitor->id);
});

it('opens the real project page for the demo visitor', function () {
    $visitor = startDemo();
    $project = $visitor->favoriteProjects()->first();

    $this->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Show')
            ->where('demo.expires_at', $visitor->demo_expires_at->toIso8601String()));
});

it('requires a Turnstile token when Turnstile is configured', function () {
    config(['services.turnstile.secret_key' => 'test-secret']);
    Http::fake();

    $this->post(route('demo.start'))->assertSessionHasErrors('cf-turnstile-response');

    expect(Organization::where('membership_tier', Organization::TIER_DEMO)->count())->toBe(0);
});

it('starts when Cloudflare accepts the Turnstile token', function () {
    config(['services.turnstile.secret_key' => 'test-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    $this->post(route('demo.start'), ['cf-turnstile-response' => 'valid-token'])->assertRedirectContains('/projects/');

    expect(auth()->user()?->isDemo())->toBeTrue();
});

it('limits each visitor IP to three sandboxes a day', function () {
    foreach (range(1, 3) as $attempt) {
        $this->post(route('demo.start'))->assertRedirectContains('/projects/');
        auth()->logout();
    }

    $this->post(route('demo.start'))->assertSessionHasErrors('demo');

    expect(Organization::where('membership_tier', Organization::TIER_DEMO)->count())->toBe(3);
});

it('stops creating sandboxes once the site-wide daily cap is reached', function () {
    config(['services.demo.daily_sandbox_limit' => 1]);

    $this->post(route('demo.start'))->assertRedirectContains('/projects/');
    auth()->logout();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->post(route('demo.start'))
        ->assertSessionHasErrors('demo');

    expect(Organization::where('membership_tier', Organization::TIER_DEMO)->count())->toBe(1);
});

it('signs the visitor out and sends them to sign up when they leave the demo', function () {
    startDemo();

    $this->post(route('demo.exit'))->assertRedirect(route('register'));

    $this->assertGuest();
});

it('blocks demo accounts from features that reach outside the app', function () {
    $visitor = startDemo();
    $organization = $visitor->organizations()->sole();

    $this->from(route('dashboard'))
        ->post(route('organizations.invite', $organization), ['first_name' => 'New', 'last_name' => 'User', 'email' => 'new@example.com', 'role' => 'team-member'])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error', RestrictDemoUsers::MESSAGE);

    $this->putJson(route('organizations.update', $organization), ['name' => 'Renamed'])
        ->assertForbidden()
        ->assertJsonPath('message', RestrictDemoUsers::MESSAGE);

    expect($organization->invitations()->count())->toBe(0)
        ->and($organization->fresh()->name)->toBe('Demo Agency');
});

it('never sends email to a demo account', function () {
    config(['mail.default' => 'array']);

    Mail::raw('Hello', fn ($message) => $message->to('visitor-abc@'.DemoSandbox::EMAIL_DOMAIN));
    Mail::raw('Hello', fn ($message) => $message->to('real@example.com'));

    $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();

    expect($sent)->toHaveCount(1)
        ->and($sent->first()->getEnvelope()->getRecipients()[0]->getAddress())->toBe('real@example.com');
});

it('stops new transcripts once the demo AI budget is used up', function () {
    $visitor = startDemo();
    $organization = $visitor->organizations()->sole();
    $project = $visitor->favoriteProjects()->first();

    foreach (range(1, 15) as $run) {
        AiUsageLog::create(['organization_id' => $organization->id, 'driver' => 'openai', 'model' => 'test', 'type' => 'llm', 'input_tokens' => 1, 'output_tokens' => 1, 'cost_usd' => 0, 'created_at' => now()]);
    }

    $this->from(route('projects.show', $project))
        ->post(route('projects.documents.store', $project), ['name' => 'Another meeting', 'type' => 'intake', 'content' => '<p>Notes</p>', 'priority' => 'low', 'task_status' => 'todo'])
        ->assertSessionHas('error', "You've used all the AI runs included in the demo. Start a free trial to keep going.");

    expect(Document::where('name', 'Another meeting')->exists())->toBeFalse();
});

it('deletes sandboxes older than a day and keeps newer ones', function () {
    $sandbox = app(DemoSandbox::class);

    ['user' => $oldVisitor] = $sandbox->create();
    $oldOrganization = $oldVisitor->organizations()->sole();

    $this->travel(DemoSandbox::LIFETIME_HOURS + 1)->hours();

    ['user' => $newVisitor] = $sandbox->create();

    $this->artisan('demo:prune')->assertSuccessful();

    expect(Organization::find($oldOrganization->id))->toBeNull()
        ->and(Client::where('organization_id', $oldOrganization->id)->exists())->toBeFalse()
        ->and(User::find($oldVisitor->id))->toBeNull()
        ->and(User::find($newVisitor->id))->not->toBeNull()
        ->and($newVisitor->organizations()->count())->toBe(1)
        ->and(Document::count())->toBe(3);
});

it('keeps the AI usage of deleted sandboxes, tagged as demo', function () {
    ['user' => $visitor, 'project' => $project] = app(DemoSandbox::class)->create();
    $organizationId = $visitor->organizations()->sole()->id;

    app(AiUsageLogger::class)->log('openai', 'gpt-4o-mini', 'llm', 1000, 500, $project);

    $this->travel(DemoSandbox::LIFETIME_HOURS + 1)->hours();
    $this->artisan('demo:prune')->assertSuccessful();

    $log = AiUsageLog::sole();
    expect(Organization::find($organizationId))->toBeNull()
        ->and($log->organization_id)->toBeNull()
        ->and($log->membership_tier)->toBe(Organization::TIER_DEMO)
        ->and($log->input_tokens)->toBe(1000)
        ->and((float) $log->cost_usd)->toBeGreaterThan(0);
});

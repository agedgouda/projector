<?php

use App\Models\AiTemplate;
use App\Models\Document;
use App\Services\Demo\DemoSamples;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    config([
        'services.openai.key' => 'test-key',
        'services.turnstile.secret_key' => null,
        'services.demo.parent_origin' => 'https://about.projecthq.app',
        'services.demo.daily_live_limit' => 300,
    ]);

    AiTemplate::query()->firstOrCreate(
        ['name' => 'Create Tasks', 'organization_id' => null],
        [
            'type' => 'workflow',
            'system_prompt' => '<p>Extract tasks. Today is {{today}}. Tags: {{available_tags}}.</p><p>Do not extract assignee or owner information.</p>',
            'user_prompt' => "Source Material:\n{{input}}\n\nUse the keys \"title\", \"{{output_key}}\", \"criteria\", \"priority\", \"due_date\", and \"tag_names\".",
        ],
    );
});

/**
 * @param  list<array<string, mixed>>  $items
 */
function fakeOpenAiTasks(array $items): void
{
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['items' => $items])]]],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 300],
        ]),
    ]);
}

function demoPayload(array $overrides = []): array
{
    return [
        'notes' => str_repeat('Maya will send the recap to the client by Friday. ', 5),
        'meeting_date' => '2026-10-13',
        'team' => ['Maya Chen', 'Luis Ortega'],
        ...$overrides,
    ];
}

it('renders the demo page with every sample and its saved result', function () {
    $this->get(route('demo.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Demo/Index')
            ->has('samples', count(DemoSamples::SAMPLES))
            ->has('samples.0.notes')
            ->has('samples.0.tasks.0.title')
            ->where('parentOrigin', 'https://about.projecthq.app'));
});

it('lets only the marketing site frame the demo page', function () {
    $response = $this->get(route('demo.show'))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'self' https://about.projecthq.app")
        ->and($response->headers->has('X-Frame-Options'))->toBeFalse();
});

it('keeps every sample within the demo length limit', function () {
    foreach (array_keys(DemoSamples::SAMPLES) as $id) {
        expect(mb_strlen(app(DemoSamples::class)->notes($id)))->toBeLessThanOrEqual(6000)->toBeGreaterThanOrEqual(100);
    }
});

it('drafts tasks from the submitted notes without saving anything', function () {
    fakeOpenAiTasks([
        [
            'title' => 'Send recap to client',
            'content' => 'Email the meeting recap.',
            'criteria' => ['Recap sent'],
            'priority' => 'high',
            'due_date' => '2026-10-16',
            'start_date' => null,
            'assignee_name' => 'maya chen',
            'tag_names' => ['Client', 'Not A Real Tag'],
        ],
    ]);

    $this->postJson(route('demo.extract'), demoPayload())
        ->assertOk()
        ->assertExactJson(['tasks' => [[
            'title' => 'Send recap to client',
            'description' => 'Email the meeting recap.',
            'criteria' => ['Recap sent'],
            'priority' => 'high',
            'due_date' => '2026-10-16',
            'assignee' => 'Maya Chen',
            'tags' => ['Client'],
        ]]]);

    Http::assertSent(function (Request $request) {
        $messages = $request['messages'];

        return str_contains($messages[0]['content'], 'Today is 2026-10-13')
            && str_contains($messages[1]['content'], '"Maya Chen", "Luis Ortega"')
            && str_contains($messages[1]['content'], 'Maya will send the recap');
    });

    expect(Document::count())->toBe(0);
    $this->assertDatabaseCount('ai_usage_logs', 0);
});

it('drops names outside the team and cleans malformed fields', function () {
    fakeOpenAiTasks([
        ['title' => 'Do the thing', 'content' => '<b>Bold</b> text', 'criteria' => 'not-a-list', 'priority' => 'urgent', 'due_date' => 'Friday', 'assignee_name' => 'Somebody Else', 'tag_names' => []],
        ['title' => '', 'content' => 'No title, so skipped'],
        'not an object',
    ]);

    $this->postJson(route('demo.extract'), demoPayload())
        ->assertOk()
        ->assertJsonCount(1, 'tasks')
        ->assertJsonPath('tasks.0.description', 'Bold text')
        ->assertJsonPath('tasks.0.criteria', [])
        ->assertJsonPath('tasks.0.priority', 'medium')
        ->assertJsonPath('tasks.0.due_date', null)
        ->assertJsonPath('tasks.0.assignee', null);
});

it('validates the notes, date and team', function (array $overrides, string $field) {
    Http::fake();

    $this->postJson(route('demo.extract'), demoPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    Http::assertNothingSent();
})->with([
    'notes too short' => [['notes' => 'Too short'], 'notes'],
    'notes too long' => [['notes' => str_repeat('a', 6001)], 'notes'],
    'missing date' => [['meeting_date' => ''], 'meeting_date'],
    'bad date' => [['meeting_date' => '10/13/2026'], 'meeting_date'],
    'duplicate team member' => [['team' => ['Maya', 'Maya']], 'team.0'],
    'too many team members' => [['team' => array_map(fn (int $i): string => "Person {$i}", range(1, 13))], 'team'],
]);

it('requires a Turnstile token when Turnstile is configured', function () {
    config(['services.turnstile.secret_key' => 'test-secret']);
    Http::fake();

    $this->postJson(route('demo.extract'), demoPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cf-turnstile-response');

    Http::assertNothingSent();
});

it('runs when Cloudflare accepts the Turnstile token', function () {
    config(['services.turnstile.secret_key' => 'test-secret']);
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['items' => []])]]],
            'usage' => [],
        ]),
    ]);

    $this->postJson(route('demo.extract'), demoPayload(['cf-turnstile-response' => 'valid-token']))
        ->assertOk()
        ->assertExactJson(['tasks' => []]);
});

it('limits each visitor to five live runs an hour', function () {
    fakeOpenAiTasks([]);

    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('demo.extract'), demoPayload())->assertOk();
    }

    $this->postJson(route('demo.extract'), demoPayload())
        ->assertTooManyRequests()
        ->assertJsonPath('message', "You've reached the demo's hourly limit. Try a sample, or come back in a bit.");
});

it('limits each visitor to fifteen live runs a day', function () {
    fakeOpenAiTasks([]);

    foreach (range(1, 15) as $attempt) {
        RateLimiter::clear(md5('demo'.'hour:127.0.0.1'));
        $this->postJson(route('demo.extract'), demoPayload())->assertOk();
    }

    RateLimiter::clear(md5('demo'.'hour:127.0.0.1'));

    $this->postJson(route('demo.extract'), demoPayload())
        ->assertTooManyRequests()
        ->assertJsonPath('message', "You've reached the demo's daily limit. Start a free trial to keep going.");
});

it('stops live runs once the site-wide daily cap is reached', function () {
    config(['services.demo.daily_live_limit' => 1]);
    fakeOpenAiTasks([]);

    $this->postJson(route('demo.extract'), demoPayload())->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson(route('demo.extract'), demoPayload())
        ->assertServiceUnavailable();

    Http::assertSentCount(1);
});

it('reports a friendly error when the AI call fails', function () {
    Http::fake(['api.openai.com/*' => Http::response('upstream error', 500)]);

    $this->postJson(route('demo.extract'), demoPayload())
        ->assertStatus(502)
        ->assertJsonPath('message', "The AI didn't respond. Please try again in a moment.");
});

it('reports a friendly error when the Create Tasks transformation is missing', function () {
    AiTemplate::query()->whereNull('organization_id')->where('name', 'Create Tasks')->delete();
    Http::fake();

    $this->postJson(route('demo.extract'), demoPayload())->assertStatus(502);

    Http::assertNothingSent();
});

it('rejects an unknown sample id when generating sample results', function () {
    $this->artisan('demo:generate-samples', ['sample' => 'nope'])->assertFailed();
});

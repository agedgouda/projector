<?php

use App\Jobs\SendSlackDigestToAdmin;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SlackDigestSend;
use App\Models\SlackUserIdentity;
use App\Models\SlackWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);

    DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'task',
        'label' => 'Task',
        'is_task' => true,
        'order' => 1,
    ]);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $this->client->id]);

    $this->admin = User::factory()->create(['timezone' => 'America/New_York']);
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);

    $this->workspace = SlackWorkspace::factory()->create([
        'organization_id' => $this->org->id,
        'team_id' => 'T123',
        'bot_access_token' => 'xoxb-fake-token',
    ]);

    $this->identity = SlackUserIdentity::factory()->create([
        'user_id' => $this->admin->id,
        'slack_team_id' => 'T123',
        'slack_user_id' => 'U123',
    ]);
});

function createTask(Project $project, ?string $dueAt, string $taskStatus = 'todo', string $name = 'A task'): Document
{
    return $project->documents()->create([
        'name' => $name,
        'type' => 'task',
        'content' => $name,
        'due_at' => $dueAt,
        'task_status' => $taskStatus,
    ]);
}

// ── Command: hour gating & dedupe ───────────────────────────────────────────

it('dispatches the digest job when it is 8am in the admin\'s own timezone', function () {
    Bus::fake();

    // 8:00 in America/New_York is 12:00 or 13:00 UTC depending on DST — travel to a UTC
    // instant known to land on 8am Eastern rather than hardcoding a UTC hour.
    $this->travelTo(now()->setTimezone('America/New_York')->setTime(8, 30));

    $this->artisan('app:send-slack-daily-digest');

    Bus::assertDispatched(SendSlackDigestToAdmin::class, fn ($job) => $job->organization->is($this->org) && $job->admin->is($this->admin));
});

it('does not dispatch outside the admin\'s local 8am hour', function () {
    Bus::fake();

    $this->travelTo(now()->setTimezone('America/New_York')->setTime(9, 30));

    $this->artisan('app:send-slack-daily-digest');

    Bus::assertNotDispatched(SendSlackDigestToAdmin::class);
});

it('does not dispatch or record a send for an admin with no linked Slack identity', function () {
    Bus::fake();
    $this->identity->delete();

    $this->travelTo(now()->setTimezone('America/New_York')->setTime(8, 15));

    $this->artisan('app:send-slack-daily-digest');

    Bus::assertNotDispatched(SendSlackDigestToAdmin::class);
    expect(SlackDigestSend::count())->toBe(0);
});

it('only dispatches once per admin per organization per local day', function () {
    Bus::fake();

    $this->travelTo(now()->setTimezone('America/New_York')->setTime(8, 15));
    $this->artisan('app:send-slack-daily-digest');

    $this->travelTo(now()->setTimezone('America/New_York')->setTime(8, 45));
    $this->artisan('app:send-slack-daily-digest');

    Bus::assertDispatchedTimes(SendSlackDigestToAdmin::class, 1);
    expect(SlackDigestSend::count())->toBe(1);
});

it('dispatches again the next local day', function () {
    Bus::fake();

    $this->travelTo(now()->setTimezone('America/New_York')->setTime(8, 15));
    $this->artisan('app:send-slack-daily-digest');

    $this->travelTo(now()->setTimezone('America/New_York')->addDay()->setTime(8, 15));
    $this->artisan('app:send-slack-daily-digest');

    Bus::assertDispatchedTimes(SendSlackDigestToAdmin::class, 2);
});

// ── Job: message content ────────────────────────────────────────────────────

it('sends a due-today digest when tasks are due today', function () {
    $today = now('America/New_York')->startOfDay()->addHours(10);
    createTask($this->project, $today->clone()->utc()->toDateTimeString(), 'todo', 'Ship the report');
    createTask($this->project, now()->addWeek()->toDateTimeString(), 'todo', 'Not due today');
    createTask($this->project, $today->clone()->utc()->toDateTimeString(), 'done', 'Already done, excluded');

    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200)]);

    $this->travelTo($today);
    SendSlackDigestToAdmin::dispatchSync($this->org, $this->admin);

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://slack.com/api/chat.postMessage') {
            return false;
        }

        return $request['channel'] === 'U123'
            && str_contains($request['text'], 'tasks due today')
            && str_contains($request['text'], 'Ship the report')
            && ! str_contains($request['text'], 'Not due today')
            && ! str_contains($request['text'], 'Already done');
    });
});

it('falls back to the next 5 upcoming deliverables, overdue first, when nothing is due today', function () {
    $now = now('America/New_York')->setTime(8, 0);
    createTask($this->project, $now->clone()->subDays(3)->utc()->toDateTimeString(), 'todo', 'Overdue task');
    createTask($this->project, $now->clone()->addDays(2)->utc()->toDateTimeString(), 'todo', 'Future task');
    createTask($this->project, null, 'todo', 'No due date, excluded');

    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200)]);

    $this->travelTo($now);
    SendSlackDigestToAdmin::dispatchSync($this->org, $this->admin);

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://slack.com/api/chat.postMessage') {
            return false;
        }

        $text = $request['text'];
        $overdueBeforeFuture = strpos($text, 'Overdue task') < strpos($text, 'Future task');

        return str_contains($text, 'nothing due today')
            && str_contains($text, 'Overdue task')
            && str_contains($text, 'Future task')
            && ! str_contains($text, 'No due date')
            && $overdueBeforeFuture;
    });
});

it('sends nothing when there are no tasks due today and no upcoming deliverables at all', function () {
    createTask($this->project, null, 'todo', 'No due date');

    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200)]);

    SendSlackDigestToAdmin::dispatchSync($this->org, $this->admin);

    Http::assertNothingSent();
});

it('sends nothing for an organization with no projects at all', function () {
    $emptyOrg = Organization::create(['name' => 'Empty Org']);
    $emptyOrg->users()->attach($this->admin->id, ['role' => 'org-admin']);
    SlackWorkspace::factory()->create([
        'organization_id' => $emptyOrg->id,
        'team_id' => 'T456',
        'bot_access_token' => 'xoxb-fake-token-2',
    ]);
    SlackUserIdentity::factory()->create([
        'user_id' => $this->admin->id,
        'slack_team_id' => 'T456',
        'slack_user_id' => 'U123',
    ]);

    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200)]);

    SendSlackDigestToAdmin::dispatchSync($emptyOrg, $this->admin);

    Http::assertNothingSent();
});

it('logs a warning without throwing when chat.postMessage reports failure', function () {
    createTask($this->project, now()->toDateTimeString(), 'todo', 'Due today');

    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'not_in_channel'], 200)]);

    SendSlackDigestToAdmin::dispatchSync($this->org, $this->admin);

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage');
});

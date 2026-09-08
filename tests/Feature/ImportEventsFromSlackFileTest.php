<?php

use App\Jobs\ImportEventsFromSlackFile;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);

    DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'event',
        'label' => 'Event',
        'is_task' => false,
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

    $this->user = User::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function slackFilePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'events.csv',
        'url_private_download' => 'https://files.slack.com/files-pri/T123-F123/events.csv',
        'mimetype' => 'text/csv',
    ], $overrides);
}

it('imports events from a downloaded csv and posts a summary in the channel', function () {
    $csv = "Name,Start Date,Due Date,Tag\nTeam Offsite,2026-09-10,2026-09-10,offsite\nQuarterly Review,2026-09-15,,";

    Http::fake([
        'files.slack.com/*' => Http::response($csv, 200),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
    ]);

    ImportEventsFromSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(), 'xoxb-fake-token', 'C123');

    $events = Document::where('project_id', $this->project->id)->where('type', 'event')->get();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('name'))->toContain('Team Offsite', 'Quarterly Review');

    $importDocument = Document::where('project_id', $this->project->id)->where('type', 'event_list_import')->first();
    expect($importDocument)->not->toBeNull()
        ->and($importDocument->creator_id)->toBe($this->user->id)
        ->and($importDocument->metadata['created_count'])->toBe(2);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['channel'] === 'C123'
            && str_contains($request['text'], 'Imported 2 events');
    });
});

it('replies with an error and imports nothing when the download fails', function () {
    Http::fake([
        'files.slack.com/*' => Http::response('', 404),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
    ]);

    ImportEventsFromSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(), 'xoxb-fake-token', 'C123');

    expect(Document::where('project_id', $this->project->id)->where('type', 'event')->count())->toBe(0);

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage' && str_contains($request['text'], "couldn't download"));
});

it('replies with an error and imports nothing when no rows are found', function () {
    Http::fake([
        'files.slack.com/*' => Http::response("Name,Start Date\n", 200),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
    ]);

    ImportEventsFromSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(), 'xoxb-fake-token', 'C123');

    expect(Document::where('project_id', $this->project->id)->where('type', 'event')->count())->toBe(0);

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage' && str_contains($request['text'], "didn't have any rows"));
});

it('replies with an error and imports nothing when no name column can be detected', function () {
    Http::fake([
        'files.slack.com/*' => Http::response("Widget,Color\nfoo,red", 200),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
    ]);

    ImportEventsFromSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(), 'xoxb-fake-token', 'C123');

    expect(Document::where('project_id', $this->project->id)->where('type', 'event')->count())->toBe(0);

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage' && str_contains($request['text'], "Couldn't find a name/title column"));
});

it('ignores a file whose extension is not importable', function () {
    Http::fake();

    ImportEventsFromSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(['name' => 'notes.pdf']), 'xoxb-fake-token', 'C123');

    Http::assertNothingSent();
});

it('logs a warning without throwing when the summary chat.postMessage fails', function () {
    Http::fake([
        'files.slack.com/*' => Http::response("Name,Start Date\nTeam Offsite,2026-09-10", 200),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'not_in_channel'], 200),
    ]);

    ImportEventsFromSlackFile::dispatchSync($this->project, $this->user, slackFilePayload(), 'xoxb-fake-token', 'C123');

    expect(Document::where('project_id', $this->project->id)->where('type', 'event')->count())->toBe(1);
});

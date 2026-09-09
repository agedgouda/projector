<?php

use App\Jobs\ImportDropboxFile;
use App\Models\Client;
use App\Models\DropboxFolderBinding;
use App\Models\DropboxWorkspace;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Dropbox\DropboxApiClient;
use Illuminate\Support\Facades\Bus;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config(['services.dropbox.client_secret' => 'fake-app-secret']);
});

function signDropboxRequest(string $body): array
{
    return ['X-Dropbox-Signature' => hash_hmac('sha256', $body, 'fake-app-secret')];
}

// ── GET verification handshake ──────────────────────────────────────────────

it('echoes back the challenge on the GET verification handshake, unsigned', function () {
    $this->get('/dropbox/events?challenge=abc123')
        ->assertOk()
        ->assertSee('abc123', false)
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

// ── Signature verification ──────────────────────────────────────────────────

it('rejects a POST notification with an invalid signature', function () {
    $this->withHeaders(['X-Dropbox-Signature' => 'not-a-real-signature'])
        ->postJson('/dropbox/events', ['list_folder' => ['accounts' => []]])
        ->assertUnauthorized();
});

it('rejects a POST notification missing the signature header', function () {
    $this->postJson('/dropbox/events', ['list_folder' => ['accounts' => []]])
        ->assertUnauthorized();
});

// ── Notification → dispatching imports ──────────────────────────────────────

function bindDropboxFolderToProject(): array
{
    $org = Organization::create(['name' => 'Test Org']);
    $client = Client::create([
        'organization_id' => $org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $project = Project::create(['name' => 'Test Project', 'client_id' => $client->id]);

    $workspace = DropboxWorkspace::factory()->create([
        'organization_id' => $org->id,
        'account_id' => 'dbid:acct123',
    ]);

    $binding = DropboxFolderBinding::factory()->create([
        'dropbox_workspace_id' => $workspace->id,
        'folder_id' => 'id:folder123',
        'folder_path' => '/intake',
        'project_id' => $project->id,
    ]);

    return compact('org', 'project', 'workspace', 'binding');
}

it('dispatches an import job for a new file in a bound folder', function () {
    Bus::fake();
    $fixture = bindDropboxFolderToProject();

    $this->mock(DropboxApiClient::class)
        ->shouldReceive('listFolder')
        ->once()
        ->andReturn([
            'entries' => [
                ['.tag' => 'file', 'name' => 'events.csv', 'path_lower' => '/intake/events.csv'],
            ],
            'cursor' => 'cursor-1',
            'has_more' => false,
        ]);

    $payload = ['list_folder' => ['accounts' => ['dbid:acct123']]];
    $body = json_encode($payload);

    $this->withHeaders(signDropboxRequest($body))
        ->postJson('/dropbox/events', $payload)
        ->assertNoContent();

    Bus::assertDispatched(ImportDropboxFile::class, function ($job) use ($fixture) {
        return $job->project->is($fixture['project'])
            && $job->workspace->is($fixture['workspace'])
            && $job->dropboxPath === '/intake/events.csv'
            && $job->filename === 'events.csv'
            && $job->subfolderName === null;
    });

    expect($fixture['workspace']->fresh()->cursor)->toBe('cursor-1');
});

it('computes the immediate subfolder name for a file nested one level under the bound folder', function () {
    Bus::fake();
    bindDropboxFolderToProject();

    $this->mock(DropboxApiClient::class)
        ->shouldReceive('listFolder')
        ->once()
        ->andReturn([
            'entries' => [
                ['.tag' => 'file', 'name' => 'standup.docx', 'path_lower' => '/intake/meeting-notes/standup.docx'],
            ],
            'cursor' => 'cursor-1',
            'has_more' => false,
        ]);

    $payload = ['list_folder' => ['accounts' => ['dbid:acct123']]];
    $body = json_encode($payload);

    $this->withHeaders(signDropboxRequest($body))
        ->postJson('/dropbox/events', $payload)
        ->assertNoContent();

    Bus::assertDispatched(ImportDropboxFile::class, fn ($job) => $job->subfolderName === 'meeting-notes');
});

it('ignores a file outside any bound folder', function () {
    Bus::fake();
    bindDropboxFolderToProject();

    $this->mock(DropboxApiClient::class)
        ->shouldReceive('listFolder')
        ->once()
        ->andReturn([
            'entries' => [
                ['.tag' => 'file', 'name' => 'random.csv', 'path_lower' => '/elsewhere/random.csv'],
            ],
            'cursor' => 'cursor-1',
            'has_more' => false,
        ]);

    $payload = ['list_folder' => ['accounts' => ['dbid:acct123']]];
    $body = json_encode($payload);

    $this->withHeaders(signDropboxRequest($body))
        ->postJson('/dropbox/events', $payload)
        ->assertNoContent();

    Bus::assertNotDispatched(ImportDropboxFile::class);
});

it('ignores a file whose extension is not importable', function () {
    Bus::fake();
    bindDropboxFolderToProject();

    $this->mock(DropboxApiClient::class)
        ->shouldReceive('listFolder')
        ->once()
        ->andReturn([
            'entries' => [
                ['.tag' => 'file', 'name' => 'photo.png', 'path_lower' => '/intake/photo.png'],
            ],
            'cursor' => 'cursor-1',
            'has_more' => false,
        ]);

    $payload = ['list_folder' => ['accounts' => ['dbid:acct123']]];
    $body = json_encode($payload);

    $this->withHeaders(signDropboxRequest($body))
        ->postJson('/dropbox/events', $payload)
        ->assertNoContent();

    Bus::assertNotDispatched(ImportDropboxFile::class);
});

it('skips folder and deleted entries', function () {
    Bus::fake();
    bindDropboxFolderToProject();

    $this->mock(DropboxApiClient::class)
        ->shouldReceive('listFolder')
        ->once()
        ->andReturn([
            'entries' => [
                ['.tag' => 'folder', 'name' => 'subfolder', 'path_lower' => '/intake/subfolder'],
                ['.tag' => 'deleted', 'name' => 'events.csv', 'path_lower' => '/intake/events.csv'],
            ],
            'cursor' => 'cursor-1',
            'has_more' => false,
        ]);

    $payload = ['list_folder' => ['accounts' => ['dbid:acct123']]];
    $body = json_encode($payload);

    $this->withHeaders(signDropboxRequest($body))
        ->postJson('/dropbox/events', $payload)
        ->assertNoContent();

    Bus::assertNotDispatched(ImportDropboxFile::class);
});

it('uses list_folder/continue with the stored cursor instead of a fresh list_folder', function () {
    Bus::fake();
    $fixture = bindDropboxFolderToProject();
    $fixture['workspace']->update(['cursor' => 'existing-cursor']);

    $this->mock(DropboxApiClient::class)
        ->shouldReceive('listFolderContinue')
        ->once()
        ->with(\Mockery::on(fn ($workspace) => $workspace->is($fixture['workspace'])), 'existing-cursor')
        ->andReturn(['entries' => [], 'cursor' => 'existing-cursor', 'has_more' => false]);

    $payload = ['list_folder' => ['accounts' => ['dbid:acct123']]];
    $body = json_encode($payload);

    $this->withHeaders(signDropboxRequest($body))
        ->postJson('/dropbox/events', $payload)
        ->assertNoContent();
});

it('ignores an account id with no matching workspace', function () {
    Bus::fake();

    $payload = ['list_folder' => ['accounts' => ['dbid:unknown']]];
    $body = json_encode($payload);

    $this->withHeaders(signDropboxRequest($body))
        ->postJson('/dropbox/events', $payload)
        ->assertNoContent();

    Bus::assertNotDispatched(ImportDropboxFile::class);
});

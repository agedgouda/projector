<?php

use App\Mail\ImportResultMail;
use App\Models\Client;
use App\Models\ImportedFile;
use App\Models\Organization;
use App\Models\PendingImport;
use App\Models\Project;
use App\Models\SlackChannelBinding;
use App\Models\SlackWorkspace;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $this->client->id]);

    $this->uploader = User::factory()->create();
    $this->org->users()->attach($this->uploader->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);

    $this->pendingImport = PendingImport::create([
        'project_id' => $this->project->id,
        'original_filename' => 'export.csv',
        'source_type' => 'spreadsheet',
        'source' => 'slack',
        'uploaded_by_user_id' => $this->uploader->id,
        'note' => 'Uploaded via Slack — needs review.',
    ]);
});

it('deletes the pending import without notifying anyone when discarded is not sent', function () {
    Mail::fake();

    $this->actingAs($this->uploader)
        ->delete(route('import.pending.destroy', $this->pendingImport))
        ->assertRedirect();

    expect(PendingImport::find($this->pendingImport->id))->toBeNull();
    Mail::assertNothingSent();
});

it('notifies the uploader by email when discarded, and there is no bound slack channel', function () {
    Mail::fake();

    $this->actingAs($this->uploader)
        ->delete(route('import.pending.destroy', $this->pendingImport), ['discarded' => true])
        ->assertRedirect();

    expect(PendingImport::find($this->pendingImport->id))->toBeNull();

    Mail::assertSent(ImportResultMail::class, fn ($mail) => str_contains($mail->resultMessage, 'Import canceled by user. Click here to restart')
        && str_contains($mail->resultMessage, route('import.index', ['org' => $this->org->id])));
});

it('posts the cancellation in-channel when the project has a bound slack channel', function () {
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200)]);

    $workspace = SlackWorkspace::factory()->create([
        'organization_id' => $this->org->id,
        'bot_access_token' => 'xoxb-fake-token',
    ]);
    SlackChannelBinding::factory()->create([
        'slack_workspace_id' => $workspace->id,
        'channel_id' => 'C123',
        'project_id' => $this->project->id,
    ]);

    $this->actingAs($this->uploader)
        ->delete(route('import.pending.destroy', $this->pendingImport), ['discarded' => true])
        ->assertRedirect();

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
        && $request['channel'] === 'C123'
        && str_contains($request['text'], 'Import canceled by user. Click here to restart'));
});

it('skips notifying when the pending import has no known uploader', function () {
    Mail::fake();

    $this->pendingImport->update(['uploaded_by_user_id' => null]);

    $this->actingAs($this->uploader)
        ->delete(route('import.pending.destroy', $this->pendingImport), ['discarded' => true])
        ->assertRedirect();

    expect(PendingImport::find($this->pendingImport->id))->toBeNull();
    Mail::assertNothingSent();
});

it('forgets the imported-file dedup record for a discarded pending import, so the same file can be re-uploaded', function () {
    $content = "Name,Due Date\nWrite report,2026-09-01\n";
    $contentHash = hash('sha256', $content);

    ImportedFile::create([
        'project_id' => $this->project->id,
        'content_hash' => $contentHash,
        'original_filename' => 'export.csv',
        'source' => 'slack',
    ]);

    $this->pendingImport->addMedia(UploadedFile::fake()->createWithContent('export.csv', $content))
        ->preservingOriginal()
        ->toMediaCollection('file');

    $this->actingAs($this->uploader)
        ->delete(route('import.pending.destroy', $this->pendingImport), ['discarded' => true])
        ->assertRedirect();

    expect(ImportedFile::where('project_id', $this->project->id)->where('content_hash', $contentHash)->exists())->toBeFalse();
});

it('leaves the imported-file dedup record alone when a pending import is dismissed after a successful apply', function () {
    $content = "Name,Due Date\nWrite report,2026-09-01\n";
    $contentHash = hash('sha256', $content);

    ImportedFile::create([
        'project_id' => $this->project->id,
        'content_hash' => $contentHash,
        'original_filename' => 'export.csv',
        'source' => 'slack',
    ]);

    $this->pendingImport->addMedia(UploadedFile::fake()->createWithContent('export.csv', $content))
        ->preservingOriginal()
        ->toMediaCollection('file');

    $this->actingAs($this->uploader)
        ->delete(route('import.pending.destroy', $this->pendingImport))
        ->assertRedirect();

    expect(ImportedFile::where('project_id', $this->project->id)->where('content_hash', $contentHash)->exists())->toBeTrue();
});

it('404s a member with no client access discarding a pending import', function () {
    // AccessDeniedHttpException (a failed Gate::authorize()) is rewritten to a 404 by this
    // app's own exception handler (bootstrap/app.php) rather than surfacing as a 403.
    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'contributor']);

    $this->actingAs($member)
        ->delete(route('import.pending.destroy', $this->pendingImport), ['discarded' => true])
        ->assertNotFound();

    expect(PendingImport::find($this->pendingImport->id))->not->toBeNull();
});

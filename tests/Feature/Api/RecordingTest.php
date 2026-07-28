<?php

use App\Jobs\TranscribeRecording;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Acme Inc']);
    $this->user = User::factory()->withoutTwoFactor()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);

    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);

    $this->projectType = ProjectType::factory()->create();

    $this->project = Project::create([
        'name' => 'Mobile Redesign',
        'client_id' => $this->client->id,
        'project_type_id' => $this->projectType->id,
    ]);

    Sanctum::actingAs($this->user);
});

it('uploads a recording and dispatches transcription', function () {
    Queue::fake();

    $audio = fakeWavFile();

    $response = $this->postJson(route('api.projects.recordings.store', $this->project), [
        'audio' => $audio,
        'name' => 'Kickoff Call',
    ]);

    $response->assertCreated();
    expect($response->json('recording.status'))->toBe('processing');
    expect($response->json('recording.name'))->toBe('Kickoff Call');

    $document = Document::findOrFail($response->json('recording.id'));
    expect($document->type)->toBe(config('workflow.intake_key'));
    expect($document->metadata['recording_source'])->toBe('mobile_recording');
    expect($document->getFirstMedia('recording'))->not->toBeNull();

    Queue::assertPushed(TranscribeRecording::class, fn ($job) => $job->document->is($document));
});

it('defaults the recording name when none is given', function () {
    Queue::fake();

    $audio = fakeWavFile();

    $response = $this->postJson(route('api.projects.recordings.store', $this->project), ['audio' => $audio]);

    $response->assertCreated();
    expect($response->json('recording.name'))->toStartWith('Recording — ');
});

it('rejects a non-audio file', function () {
    $file = UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf');

    $this->postJson(route('api.projects.recordings.store', $this->project), ['audio' => $file])
        ->assertUnprocessable();
});

it('blocks uploads from a user outside the project\'s organization', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $outsider = User::factory()->withoutTwoFactor()->create();
    $otherOrg->users()->attach($outsider->id, ['role' => 'org-admin']);
    Sanctum::actingAs($outsider);

    $audio = fakeWavFile();

    $this->postJson(route('api.projects.recordings.store', $this->project), ['audio' => $audio])
        ->assertNotFound();
});

it('lists only the current user\'s own recordings', function () {
    $ownDocument = $this->project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'My Recording',
        'content' => '',
        'processed_at' => null,
        'creator_id' => $this->user->id,
        'metadata' => ['recording_source' => 'mobile_recording'],
    ]);

    $otherUser = User::factory()->withoutTwoFactor()->create();
    $this->org->users()->attach($otherUser->id, ['role' => 'team-member']);
    $this->client->users()->attach($otherUser->id);
    $this->project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Someone Else\'s Recording',
        'content' => '',
        'processed_at' => null,
        'creator_id' => $otherUser->id,
        'metadata' => ['recording_source' => 'mobile_recording'],
    ]);

    $response = $this->getJson(route('api.recordings.index'));

    $response->assertOk();
    expect($response->json('recordings'))->toHaveCount(1);
    expect($response->json('recordings.0.id'))->toBe($ownDocument->id);
});

it('shows a recording with its resulting action items', function () {
    $document = $this->project->documents()->create([
        'type' => 'action_items',
        'name' => 'Kickoff Call',
        'content' => 'Full transcript here.',
        'processed_at' => now(),
        'creator_id' => $this->user->id,
        'metadata' => ['recording_source' => 'mobile_recording'],
    ]);

    $child = Document::create([
        'project_id' => $this->project->id,
        'parent_id' => $document->id,
        'type' => 'task',
        'name' => 'Follow up with client',
        'content' => 'Send the proposal.',
    ]);

    $response = $this->getJson(route('api.recordings.show', $document));

    $response->assertOk();
    expect($response->json('recording.content'))->toBe('Full transcript here.');
    expect($response->json('recording.children'))->toHaveCount(1);
    expect($response->json('recording.children.0.id'))->toBe($child->id);
});

it('blocks viewing a recording from another organization', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherClient = Client::create([
        'organization_id' => $otherOrg->id,
        'company_name' => 'Other Client',
        'contact_name' => 'John Doe',
        'contact_phone' => '555-5678',
    ]);
    $otherProject = Project::create([
        'name' => 'Other Project',
        'client_id' => $otherClient->id,
        'project_type_id' => $this->projectType->id,
    ]);
    $otherDocument = $otherProject->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Other Recording',
        'content' => '',
        'metadata' => ['recording_source' => 'mobile_recording'],
    ]);

    $this->getJson(route('api.recordings.show', $otherDocument))->assertNotFound();
});

it('confirms a recording and deletes the source audio', function () {
    $document = $this->project->documents()->create([
        'type' => 'action_items',
        'name' => 'Kickoff Call',
        'content' => 'Transcript.',
        'processed_at' => now(),
        'creator_id' => $this->user->id,
        'metadata' => ['recording_source' => 'mobile_recording', 'audio_status' => 'pending'],
    ]);
    $document->addMedia(fakeWavFile())->toMediaCollection('recording');

    $response = $this->postJson(route('api.recordings.confirm', $document));

    $response->assertOk();
    expect($response->json('recording.audio_status'))->toBe('approved');
    expect($document->fresh()->getFirstMedia('recording'))->toBeNull();
});

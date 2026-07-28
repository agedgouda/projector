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
});

it('uploads a recording via the mobile page tree using session auth, not a Sanctum token', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->postJson(route('mobile.record.store', $this->project), [
            'audio' => fakeWavFile(),
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

it('rejects a mobile recording upload for a project outside the user\'s organization', function () {
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

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->postJson(route('mobile.record.store', $otherProject), ['audio' => fakeWavFile()])
        ->assertNotFound();
});

it('rejects a non-audio file uploaded from the mobile recording screen', function () {
    $file = UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf');

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->postJson(route('mobile.record.store', $this->project), ['audio' => $file])
        ->assertUnprocessable();
});

it('reports processing status while transcription is still running', function () {
    Queue::fake();

    $document = $this->project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Recording — pending',
        'content' => '',
        'processed_at' => now(),
        'metadata' => ['recording_source' => 'mobile_recording', 'audio_status' => 'pending'],
    ]);

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->getJson(route('mobile.record.status', [$this->project, $document]))
        ->assertOk()
        ->assertJsonPath('recording.status', 'processing');
});

it('reports processed status once transcription has landed', function () {
    $document = $this->project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Recording — done',
        'content' => 'The transcript text.',
        'processed_at' => now(),
        'metadata' => ['recording_source' => 'mobile_recording', 'audio_status' => 'pending'],
    ]);

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->getJson(route('mobile.record.status', [$this->project, $document]))
        ->assertOk()
        ->assertJsonPath('recording.status', 'processed');
});

it('reports failed status when transcription errored', function () {
    $document = $this->project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Recording — failed',
        'content' => '',
        'processed_at' => now(),
        'metadata' => [
            'recording_source' => 'mobile_recording',
            'audio_status' => 'pending',
            'transcription_error' => 'No speech detected in the recording.',
        ],
    ]);

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->getJson(route('mobile.record.status', [$this->project, $document]))
        ->assertOk()
        ->assertJsonPath('recording.status', 'failed');
});

it('404s a status check when the document does not belong to the given project', function () {
    $otherProject = Project::create([
        'name' => 'Other Project In Same Org',
        'client_id' => $this->client->id,
        'project_type_id' => $this->projectType->id,
    ]);
    $document = $otherProject->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Recording',
        'content' => '',
    ]);

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->getJson(route('mobile.record.status', [$this->project, $document]))
        ->assertNotFound();
});

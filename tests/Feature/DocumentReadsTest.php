<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
    ]);
    $this->user = User::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);

    $this->document = $this->project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Recording — browser capture',
        'content' => 'Transcript text.',
        'processed_at' => now(),
        'metadata' => ['recording_source' => 'browser_capture', 'audio_status' => 'pending'],
    ]);
});

it('marks a document as read the first time the user opens it', function () {
    expect($this->document->readers()->where('users.id', $this->user->id)->exists())->toBeFalse();

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('projects.documents.show', [$this->project, $this->document]))
        ->assertOk();

    expect($this->document->readers()->where('users.id', $this->user->id)->exists())->toBeTrue();
});

it('does not duplicate the read row when a document is opened twice', function () {
    $this->actingAs($this->user)->withSession(['active_org_id' => $this->org->id])
        ->get(route('projects.documents.show', [$this->project, $this->document]));
    $this->actingAs($this->user)->withSession(['active_org_id' => $this->org->id])
        ->get(route('projects.documents.show', [$this->project, $this->document]));

    expect($this->document->readers()->where('users.id', $this->user->id)->count())->toBe(1);
});

it('only reports this project\'s read documents on the project page', function () {
    $otherProject = Project::create([
        'name' => 'Other Project',
        'client_id' => $this->client->id,
    ]);
    $otherDocument = $otherProject->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Other recording',
        'content' => 'Other transcript.',
        'processed_at' => now(),
        'metadata' => ['recording_source' => 'mobile_recording', 'audio_status' => 'pending'],
    ]);

    $this->user->readDocuments()->attach([$this->document->id, $otherDocument->id]);

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page
            ->where('readDocumentIds', fn ($ids) => collect($ids)->contains($this->document->id)
                && ! collect($ids)->contains($otherDocument->id))
        );
});

it('leaves an unread async-imported document out of readDocumentIds until opened', function () {
    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page
            ->where('readDocumentIds', fn ($ids) => ! collect($ids)->contains($this->document->id))
        );
});

<?php

use App\Models\Client;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Import\ForcedTypeMatcher;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    DocumentTypeDefinition::create(['organization_id' => null, 'key' => 'task', 'label' => 'Task', 'is_task' => true, 'order' => 1]);
    DocumentTypeDefinition::create(['organization_id' => null, 'key' => 'event', 'label' => 'Event', 'is_task' => false, 'order' => 2]);
    DocumentTypeDefinition::create(['organization_id' => null, 'key' => 'meeting_notes', 'label' => 'Meeting Notes', 'is_task' => false, 'order' => 3]);
    DocumentTypeDefinition::create(['organization_id' => null, 'key' => 'transcription', 'label' => 'Transcription', 'is_task' => false, 'order' => 4]);

    $org = Organization::create(['name' => 'Test Org']);
    $client = Client::create([
        'organization_id' => $org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $client->id]);
    $this->matcher = new ForcedTypeMatcher;
});

it('matches a #tag found in any tag signal', function () {
    $match = $this->matcher->match($this->project, ['standup-notes.docx', 'here you go #meeting-notes']);

    expect($match?->key)->toBe('meeting_notes');
});

it('matches a #tag in the filename alone, with no other signals', function () {
    $match = $this->matcher->match($this->project, ['standup-#transcription.docx']);

    expect($match?->key)->toBe('transcription');
});

it('matches an exact folder name with no # prefix needed', function () {
    $match = $this->matcher->match($this->project, ['standup.docx'], exactFolderName: 'meeting-notes');

    expect($match?->key)->toBe('meeting_notes');
});

it('is case-insensitive for both #tag and folder-name matches', function () {
    expect($this->matcher->match($this->project, ['#MEETING-NOTES'])?->key)->toBe('meeting_notes');
    expect($this->matcher->match($this->project, [], exactFolderName: 'Meeting-Notes')?->key)->toBe('meeting_notes');
});

it('returns null when a folder name only partially matches a type code', function () {
    $match = $this->matcher->match($this->project, [], exactFolderName: 'meeting-notes-archive');

    expect($match)->toBeNull();
});

it('never matches task or event, even when explicitly tagged', function () {
    expect($this->matcher->match($this->project, ['#task']))->toBeNull();
    expect($this->matcher->match($this->project, ['#event']))->toBeNull();
});

it('returns null when two different types are tagged across signals', function () {
    $match = $this->matcher->match($this->project, ['#meeting-notes'], exactFolderName: 'transcription');

    expect($match)->toBeNull();
});

it('returns null when no signal has any tag at all', function () {
    $match = $this->matcher->match($this->project, ['plain-filename.docx', 'no tags here']);

    expect($match)->toBeNull();
});

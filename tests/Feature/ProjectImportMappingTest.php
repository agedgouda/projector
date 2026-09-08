<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectImportMapping;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Test Org']);
    $client = Client::create([
        'organization_id' => $org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $client->id]);
    $this->otherProject = Project::create(['name' => 'Other Project', 'client_id' => $client->id]);
    $this->user = User::factory()->create();
});

const MAPPING_A = ['name' => 'Task Name', 'due_at' => 'Due Date', 'priority' => null, 'task_status' => null, 'assignee' => null, 'start_date' => null, 'description' => null, 'tag' => null];
const MAPPING_B = ['name' => 'Title', 'due_at' => 'Deadline', 'priority' => null, 'task_status' => null, 'assignee' => null, 'start_date' => null, 'description' => null, 'tag' => null];

it('reports a mapping as unknown before it has ever been recorded', function () {
    expect(ProjectImportMapping::isKnown($this->project, 'task', MAPPING_A))->toBeFalse();
});

it('reports a mapping as known once recorded', function () {
    ProjectImportMapping::record($this->project, 'task', MAPPING_A, $this->user);

    expect(ProjectImportMapping::isKnown($this->project, 'task', MAPPING_A))->toBeTrue();
});

it('treats key order as irrelevant to the mapping fingerprint', function () {
    ProjectImportMapping::record($this->project, 'task', MAPPING_A, $this->user);

    $reordered = array_reverse(MAPPING_A, true);

    expect(ProjectImportMapping::isKnown($this->project, 'task', $reordered))->toBeTrue();
});

it('does not recognize a different mapping as known', function () {
    ProjectImportMapping::record($this->project, 'task', MAPPING_A, $this->user);

    expect(ProjectImportMapping::isKnown($this->project, 'task', MAPPING_B))->toBeFalse();
});

it('scopes known mappings per project', function () {
    ProjectImportMapping::record($this->project, 'task', MAPPING_A, $this->user);

    expect(ProjectImportMapping::isKnown($this->otherProject, 'task', MAPPING_A))->toBeFalse();
});

it('scopes known mappings per list_type', function () {
    ProjectImportMapping::record($this->project, 'task', MAPPING_A, $this->user);

    expect(ProjectImportMapping::isKnown($this->project, 'event', MAPPING_A))->toBeFalse();
});

it('does not duplicate a row when the same mapping is recorded again', function () {
    ProjectImportMapping::record($this->project, 'task', MAPPING_A, $this->user);
    ProjectImportMapping::record($this->project, 'task', MAPPING_A, $this->user);

    expect(ProjectImportMapping::where('project_id', $this->project->id)->count())->toBe(1);
});

it('recognizes a mapping with only its non-null keys present as the same as one with every key explicit', function () {
    // The AI classifier always sends all eight keys (even as null); a web request validated by
    // ApplyImportTransformationRequest only carries whichever optional keys were submitted —
    // both have to fingerprint identically for a mapping confirmed through either path to be
    // recognized as "already known" via the other.
    $partial = ['name' => 'Task Name', 'due_at' => 'Due Date'];

    ProjectImportMapping::record($this->project, 'task', $partial, $this->user);

    expect(ProjectImportMapping::isKnown($this->project, 'task', MAPPING_A))->toBeTrue();
});

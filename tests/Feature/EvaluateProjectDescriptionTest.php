<?php

use App\Contracts\LlmDriver;
use App\Jobs\EvaluateProjectDescription;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $projectType = ProjectType::create(['name' => 'General', 'document_schema' => []]);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
        'project_type_id' => $projectType->id,
        'description' => 'This platform manages HR workflows including onboarding, payroll integration, and compliance reporting for mid-sized companies.',
    ]);
});

it('stores good quality when the LLM returns good', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [['title' => 'good', 'content' => 'Specific and informative.', 'criteria' => []]],
        ]);

    (new EvaluateProjectDescription($this->project))->handle();

    expect($this->project->fresh()->description_quality)->toBe('good');
});

it('stores vague quality when the LLM returns vague', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [['title' => 'vague', 'content' => 'Too generic.', 'criteria' => []]],
        ]);

    (new EvaluateProjectDescription($this->project))->handle();

    expect($this->project->fresh()->description_quality)->toBe('vague');
});

it('does not update quality when the LLM returns an unexpected verdict', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [['title' => 'excellent', 'content' => 'Some explanation.', 'criteria' => []]],
        ]);

    (new EvaluateProjectDescription($this->project))->handle();

    expect($this->project->fresh()->description_quality)->toBeNull();
});

it('does not update quality when the LLM call fails', function () {
    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'error', 'message' => 'API unavailable']);

    (new EvaluateProjectDescription($this->project))->handle();

    expect($this->project->fresh()->description_quality)->toBeNull();
});

it('dispatches the job when a project with a description is created via controller', function () {
    Queue::fake();

    $user = \App\Models\User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);

    $this->actingAs($user)
        ->post(route('projects.store'), [
            'name' => 'New Project',
            'client_id' => $this->client->id,
            'project_type_id' => ProjectType::first()->id,
            'description' => 'A meaningful description that should trigger evaluation.',
        ]);

    Queue::assertPushed(EvaluateProjectDescription::class);
});

it('does not dispatch the job when a project is created without a description via controller', function () {
    Queue::fake();

    $user = \App\Models\User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);

    $this->actingAs($user)
        ->post(route('projects.store'), [
            'name' => 'No Description Project',
            'client_id' => $this->client->id,
            'project_type_id' => ProjectType::first()->id,
        ]);

    Queue::assertNotPushed(EvaluateProjectDescription::class);
});

it('resets description_quality to null and dispatches job when description changes', function () {
    Queue::fake();

    $this->project->update(['description_quality' => 'good']);

    $user = \App\Models\User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);

    $this->actingAs($user)
        ->patch(route('projects.update', $this->project), [
            'name' => $this->project->name,
            'description' => 'A completely different and updated project description.',
        ]);

    expect($this->project->fresh()->description_quality)->toBeNull();

    Queue::assertPushed(EvaluateProjectDescription::class, fn ($job) => $job->project->is($this->project));
});

it('does not reset description_quality when description is unchanged on update', function () {
    Queue::fake();

    $this->project->update(['description_quality' => 'good']);

    $user = \App\Models\User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);

    $this->actingAs($user)
        ->patch(route('projects.update', $this->project), [
            'name' => 'Updated Name Only',
            'description' => $this->project->description,
        ]);

    expect($this->project->fresh()->description_quality)->toBe('good');
});

// ── /projects/evaluate-description endpoint ───────────────────────────────────

it('evaluate endpoint returns good when LLM says good', function () {
    $user = User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [['title' => 'good', 'content' => 'Specific.', 'criteria' => []]],
        ]);

    $this->actingAs($user)
        ->postJson(route('projects.evaluate-description'), [
            'description' => 'This platform manages HR workflows including onboarding, payroll, and compliance.',
            'client_id' => $this->client->id,
        ])
        ->assertOk()
        ->assertJson(['quality' => 'good', 'suggestions' => []]);
});

it('evaluate endpoint returns vague when LLM says vague', function () {
    $user = User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [['title' => 'vague', 'content' => 'Too short.', 'criteria' => ['What industry is this for?', 'What is the main goal?']]],
        ]);

    $this->actingAs($user)
        ->postJson(route('projects.evaluate-description'), [
            'description' => 'Internal tool.',
            'client_id' => $this->client->id,
        ])
        ->assertOk()
        ->assertJson(['quality' => 'vague', 'suggestions' => ['What industry is this for?', 'What is the main goal?']]);
});

it('evaluate endpoint fails open when LLM errors', function () {
    $user = User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'error', 'message' => 'API down']);

    $this->actingAs($user)
        ->postJson(route('projects.evaluate-description'), [
            'description' => 'Some description here.',
            'client_id' => $this->client->id,
        ])
        ->assertOk()
        ->assertJson(['quality' => 'good', 'suggestions' => []]);
});

it('evaluate endpoint requires authentication', function () {
    $this->postJson(route('projects.evaluate-description'), [
        'description' => 'A description.',
        'client_id' => $this->client->id,
    ])->assertUnauthorized();
});

it('evaluate endpoint validates required fields', function () {
    $user = User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);

    $this->actingAs($user)
        ->postJson(route('projects.evaluate-description'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['description', 'client_id']);
});

<?php

use App\Contracts\LlmDriver;
use App\Models\AiTemplate;
use App\Models\AiUsageLog;
use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->orgA = Organization::create(['name' => 'Org A']);
    $this->orgB = Organization::create(['name' => 'Org B']);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super-admin');

    $this->orgAAdmin = User::factory()->create();
    $this->orgA->users()->attach($this->orgAAdmin->id, ['role' => 'org-admin']);

    $this->orgBMember = User::factory()->create();
    $this->orgB->users()->attach($this->orgBMember->id, ['role' => 'team-member']);

    $this->globalTemplate = AiTemplate::create([
        'name' => 'Global Template',
        'type' => 'workflow',
        'system_prompt' => 'sys',
        'user_prompt' => 'usr',
    ]);

    $this->orgATemplate = AiTemplate::create([
        'name' => 'Org A Template',
        'type' => 'workflow',
        'organization_id' => $this->orgA->id,
        'system_prompt' => 'sys',
        'user_prompt' => 'usr',
    ]);

    $this->orgBTemplate = AiTemplate::create([
        'name' => 'Org B Template',
        'type' => 'workflow',
        'organization_id' => $this->orgB->id,
        'system_prompt' => 'sys',
        'user_prompt' => 'usr',
    ]);
});

it('super-admin sees all templates', function () {
    setPermissionsTeamId(null);

    $response = $this->actingAs($this->superAdmin)
        ->get(route('ai-templates.index'));

    $response->assertOk();

    $names = collect($response->original->getData()['page']['props']['templates'])->pluck('name');
    expect($names)->toContain('Global Template')
        ->toContain('Org A Template')
        ->toContain('Org B Template');
});

it('org-admin sees global and their org templates only', function () {
    setPermissionsTeamId($this->orgA->id);

    $response = $this->actingAs($this->orgAAdmin)
        ->get(route('ai-templates.index'));

    $response->assertOk();

    $names = collect($response->original->getData()['page']['props']['templates'])->pluck('name');
    expect($names)->toContain('Global Template')
        ->toContain('Org A Template')
        ->not->toContain('Org B Template');
});

it('super-admin sees the universal intake -> action_items template', function () {
    $universalTemplate = AiTemplate::create([
        'name' => 'Notes to Action Items',
        'type' => 'workflow',
        'system_prompt' => 'sys',
        'user_prompt' => 'usr',
    ]);
    config(['workflow.intake_to_action_items_ai_template_id' => $universalTemplate->id]);

    setPermissionsTeamId(null);

    $response = $this->actingAs($this->superAdmin)
        ->get(route('ai-templates.index'));

    $response->assertOk();

    $names = collect($response->original->getData()['page']['props']['templates'])->pluck('name');
    expect($names)->toContain('Notes to Action Items');
});

it('org-admin does not see the universal intake -> action_items template', function () {
    $universalTemplate = AiTemplate::create([
        'name' => 'Notes to Action Items',
        'type' => 'workflow',
        'system_prompt' => 'sys',
        'user_prompt' => 'usr',
    ]);
    config(['workflow.intake_to_action_items_ai_template_id' => $universalTemplate->id]);

    setPermissionsTeamId($this->orgA->id);

    $response = $this->actingAs($this->orgAAdmin)
        ->get(route('ai-templates.index'));

    $response->assertOk();

    $names = collect($response->original->getData()['page']['props']['templates'])->pluck('name');
    expect($names)->not->toContain('Notes to Action Items')
        ->toContain('Global Template');
});

it('org-admin can create a template for their org', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->post(route('ai-templates.store'), [
            'name' => 'New Org A Template',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
        ])
        ->assertRedirect();

    $created = AiTemplate::where('name', 'New Org A Template')->firstOrFail();
    expect($created->organization_id)->toBe($this->orgA->id);
});

it('redirects to the detail page after creating a template', function () {
    setPermissionsTeamId($this->orgA->id);

    $response = $this->actingAs($this->orgAAdmin)
        ->post(route('ai-templates.store'), [
            'name' => 'Redirect Target Template',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
        ]);

    $created = AiTemplate::where('name', 'Redirect Target Template')->firstOrFail();
    $response->assertRedirect(route('ai-templates.show', $created));
});

it('renders the create form with a null aiTemplate prop', function () {
    setPermissionsTeamId($this->orgA->id);

    $response = $this->actingAs($this->orgAAdmin)
        ->get(route('ai-templates.create'));

    $response->assertOk();
    expect($response->original->getData()['page']['props']['aiTemplate'])->toBeNull();
});

it('saves a description when creating a template', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->post(route('ai-templates.store'), [
            'name' => 'Described Template',
            'description' => 'Turns raw meeting notes into a polished user story',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
        ])
        ->assertRedirect();

    $created = AiTemplate::where('name', 'Described Template')->firstOrFail();
    expect($created->description)->toBe('Turns raw meeting notes into a polished user story');
});

it('saves single_output when creating a template', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->post(route('ai-templates.store'), [
            'name' => 'Single Output Template',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
            'single_output' => true,
        ])
        ->assertRedirect();

    $created = AiTemplate::where('name', 'Single Output Template')->firstOrFail();
    expect($created->single_output)->toBeTrue();
});

it('defaults single_output to false when not provided', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->post(route('ai-templates.store'), [
            'name' => 'Default Output Template',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
        ])
        ->assertRedirect();

    $created = AiTemplate::where('name', 'Default Output Template')->firstOrFail();
    expect($created->single_output)->toBeFalse();
});

it('saves output_key when creating a template', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->post(route('ai-templates.store'), [
            'name' => 'Keyed Template',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
            'output_key' => 'requirement',
        ])
        ->assertRedirect();

    $created = AiTemplate::where('name', 'Keyed Template')->firstOrFail();
    expect($created->output_key)->toBe('requirement');
});

it('rejects an output_key with characters other than lowercase letters, numbers, and underscores', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->post(route('ai-templates.store'), [
            'name' => 'Bad Key Template',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
            'output_key' => 'Not A Slug!',
        ])
        ->assertSessionHasErrors('output_key');

    expect(AiTemplate::where('name', 'Bad Key Template')->exists())->toBeFalse();
});

it('super-admin creates a global template', function () {
    setPermissionsTeamId(null);

    $this->actingAs($this->superAdmin)
        ->post(route('ai-templates.store'), [
            'name' => 'New Global Template',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
        ])
        ->assertRedirect();

    $created = AiTemplate::where('name', 'New Global Template')->firstOrFail();
    expect($created->organization_id)->toBeNull();
});

it('org-admin can update their own org template', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->put(route('ai-templates.update', $this->orgATemplate), [
            'name' => 'Updated',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
        ])
        ->assertRedirect();

    expect($this->orgATemplate->fresh()->name)->toBe('Updated');
});

it('updates a template description', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->put(route('ai-templates.update', $this->orgATemplate), [
            'name' => 'Updated',
            'description' => 'New description',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
        ])
        ->assertRedirect();

    expect($this->orgATemplate->fresh()->description)->toBe('New description');
});

it('org-admin cannot update a global template', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->put(route('ai-templates.update', $this->globalTemplate), [
            'name' => 'Hacked',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
        ])
        ->assertNotFound();
});

it('org-admin cannot update another org template', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->put(route('ai-templates.update', $this->orgBTemplate), [
            'name' => 'Hacked',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
        ])
        ->assertNotFound();
});

it('generates a system_prompt and user_prompt from a brief', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                'system_prompt' => 'You are an expert at writing user stories.',
                'user_prompt' => 'Convert these notes into a user story: {{input}}',
            ],
            'input_tokens' => 100,
            'output_tokens' => 50,
            'driver' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

    $response = $this->actingAs($this->orgAAdmin)
        ->postJson(route('ai-templates.generate-prompts'), [
            'brief' => 'Turn raw meeting notes into a polished user story',
        ]);

    $response->assertOk();
    expect($response->json('system_prompt'))->toContain('You are an expert at writing user stories.');
    expect($response->json('user_prompt'))->toBe('Convert these notes into a user story: {{input}}');

    expect(AiUsageLog::where('type', 'ai_template_generate')->where('organization_id', $this->orgA->id)->exists())->toBeTrue();
});

it('instructs the prompt generator to write English-only prompts', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt) => str_contains($systemPrompt, 'English only'))
        ->andReturn([
            'status' => 'success',
            'content' => [
                'system_prompt' => 'You are an expert.',
                'user_prompt' => 'Convert: {{input}}',
            ],
        ]);

    $this->actingAs($this->orgAAdmin)
        ->postJson(route('ai-templates.generate-prompts'), [
            'brief' => 'Anything',
        ])
        ->assertOk();
});

it('converts a markdown system_prompt into HTML for the rich text editor', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                'system_prompt' => "You are an expert.\n\nRules:\n- Be concise\n- Be accurate",
                'user_prompt' => 'Convert: {{input}}',
            ],
            'input_tokens' => 10,
            'output_tokens' => 5,
            'driver' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

    $response = $this->actingAs($this->orgAAdmin)
        ->postJson(route('ai-templates.generate-prompts'), [
            'brief' => 'Anything',
        ]);

    $response->assertOk();
    $systemPrompt = $response->json('system_prompt');
    expect($systemPrompt)->toContain('<ul>')
        ->toContain('<li>Be concise</li>')
        ->not->toContain('- Be concise');
});

it('converts a markdown table in a generated system_prompt to an HTML table', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn([
            'status' => 'success',
            'content' => [
                'system_prompt' => "| Field | Value |\n|---|---|\n| Name | Acme |",
                'user_prompt' => 'Convert: {{input}}',
            ],
            'input_tokens' => 10,
            'output_tokens' => 5,
            'driver' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

    $response = $this->actingAs($this->orgAAdmin)
        ->postJson(route('ai-templates.generate-prompts'), [
            'brief' => 'Anything',
        ]);

    $response->assertOk();
    expect($response->json('system_prompt'))->toContain('<table>')
        ->toContain('<th>Field</th>');
});

it('returns an error response when generation fails', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'error', 'message' => 'Upstream failure']);

    $this->actingAs($this->orgAAdmin)
        ->postJson(route('ai-templates.generate-prompts'), [
            'brief' => 'Turn raw meeting notes into a polished user story',
        ])
        ->assertStatus(422);
});

it('blocks a team member from generating prompts', function () {
    setPermissionsTeamId($this->orgB->id);

    $this->actingAs($this->orgBMember)
        ->postJson(route('ai-templates.generate-prompts'), [
            'brief' => 'Unauthorized attempt',
        ])
        ->assertNotFound();
});

it('team member cannot create a template', function () {
    setPermissionsTeamId($this->orgB->id);

    $this->actingAs($this->orgBMember)
        ->post(route('ai-templates.store'), [
            'name' => 'Unauthorized',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
        ])
        ->assertNotFound();
});

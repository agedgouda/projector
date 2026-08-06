<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');

    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Acme Inc']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create([
        'name' => 'Mobile Redesign',
        'client_id' => $this->client->id,
    ]);

    $this->member = User::factory()->create();
    $this->org->users()->attach($this->member->id, ['role' => 'team-member']);
    $this->client->users()->attach($this->member->id);
});

it('uploads a file and returns its public url', function () {
    $file = UploadedFile::fake()->create('diagram.png', 100);

    $response = $this->actingAs($this->member)
        ->postJson(route('projects.content-uploads.store', $this->project), ['file' => $file]);

    $response->assertOk();
    expect($response->json('name'))->toBe('diagram.png');
    expect($response->json('url'))->toContain("content-uploads/{$this->project->id}/");

    $path = "content-uploads/{$this->project->id}/".basename(parse_url($response->json('url'), PHP_URL_PATH));
    Storage::disk('public')->assertExists($path);
});

it('blocks a user from another org from uploading', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $stranger = User::factory()->create();
    $otherOrg->users()->attach($stranger->id, ['role' => 'team-member']);

    $otherClient = Client::create([
        'organization_id' => $otherOrg->id,
        'company_name' => 'Other Client',
        'contact_name' => 'Bob',
        'contact_phone' => '555-0000',
    ]);
    $otherClient->users()->attach($stranger->id);

    $file = UploadedFile::fake()->create('diagram.png', 100);

    $this->actingAs($stranger)
        ->postJson(route('projects.content-uploads.store', $this->project), ['file' => $file])
        ->assertNotFound();
});

it('rejects a file over the size cap', function () {
    $file = UploadedFile::fake()->create('huge.png', 2049); // just over the 2MB cap

    $this->actingAs($this->member)
        ->postJson(route('projects.content-uploads.store', $this->project), ['file' => $file])
        ->assertUnprocessable();
});

it('accepts a file right at the size cap', function () {
    $file = UploadedFile::fake()->create('at-limit.png', 2048); // exactly 2MB

    $this->actingAs($this->member)
        ->postJson(route('projects.content-uploads.store', $this->project), ['file' => $file])
        ->assertOk();
});

it('rejects a blocked file extension', function () {
    $file = UploadedFile::fake()->create('evil.php', 10);

    $this->actingAs($this->member)
        ->postJson(route('projects.content-uploads.store', $this->project), ['file' => $file])
        ->assertUnprocessable();
});

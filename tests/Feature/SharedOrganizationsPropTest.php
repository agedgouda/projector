<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');

    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Logo Org']);
    $this->user = User::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);
});

it('includes a null logo_url in the shared organizations prop when no logo is set', function () {
    $response = $this->actingAs($this->user)->get(route('dashboard'));

    $response->assertOk();

    $organizations = collect($response->original->getData()['page']['props']['organizations']);
    $org = $organizations->firstWhere('id', $this->org->id);

    expect($org)->not->toBeNull()
        ->and($org['name'])->toBe('Logo Org')
        ->and($org['logo_url'])->toBeNull();
});

it('includes the uploaded logo_url in the shared organizations prop', function () {
    $this->actingAs($this->user)
        ->post(route('organizations.logo.store', $this->org), ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)])
        ->assertRedirect();

    $response = $this->actingAs($this->user)->get(route('dashboard'));

    $response->assertOk();

    $organizations = collect($response->original->getData()['page']['props']['organizations']);
    $org = $organizations->firstWhere('id', $this->org->id);

    expect($org['logo_url'])->not->toBeNull()
        ->and($org['logo_url'])->toContain('logo');
});

<?php

use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);

    setPermissionsTeamId($this->org->id);
});

it('requires the organization\'s own key to choose gemini', function (string $type) {
    $this->actingAs($this->admin)
        ->patch(route('organizations.update', $this->org), [
            'name' => 'Test Org',
            "{$type}_driver" => 'gemini',
            "{$type}_config" => ['model' => 'gemini-2.0-flash', 'key' => ''],
        ])
        ->assertSessionHasErrors(["{$type}_config.key" => 'Google Gemini requires your own API key.']);

    expect($this->org->fresh()->{"{$type}_driver"})->toBeNull();
})->with(['llm', 'vector']);

it('does not reuse a stored key saved for a different driver', function () {
    $this->org->update([
        'llm_driver' => 'openai',
        'llm_config' => ['key' => 'org-openai-key', 'model' => 'gpt-4o'],
    ]);

    $this->actingAs($this->admin)
        ->patch(route('organizations.update', $this->org), [
            'name' => 'Test Org',
            'llm_driver' => 'gemini',
            'llm_config' => ['model' => 'gemini-2.0-flash'],
        ])
        ->assertSessionHasErrors('llm_config.key');

    expect($this->org->fresh()->llm_driver)->toBe('openai');
});

it('accepts gemini with a submitted key', function () {
    $this->actingAs($this->admin)
        ->patch(route('organizations.update', $this->org), [
            'name' => 'Test Org',
            'llm_driver' => 'gemini',
            'llm_config' => ['model' => 'gemini-2.0-flash', 'key' => 'org-gemini-key'],
        ])
        ->assertSessionHasNoErrors();

    expect($this->org->fresh()->llm_config['key'])->toBe('org-gemini-key');
});

it('keeps an already stored gemini key when the field is left blank', function () {
    $this->org->update([
        'llm_driver' => 'gemini',
        'llm_config' => ['key' => 'org-gemini-key', 'model' => 'gemini-2.0-flash'],
    ]);

    $this->actingAs($this->admin)
        ->patch(route('organizations.update', $this->org), [
            'name' => 'Test Org',
            'llm_driver' => 'gemini',
            'llm_config' => ['model' => 'gemini-2.0-flash', 'key' => ''],
        ])
        ->assertSessionHasNoErrors();

    expect($this->org->fresh()->llm_config['key'])->toBe('org-gemini-key');
});

it('does not require a key for other drivers', function () {
    $this->actingAs($this->admin)
        ->patch(route('organizations.update', $this->org), [
            'name' => 'Test Org',
            'llm_driver' => 'openai',
            'llm_config' => ['model' => 'gpt-4o-mini', 'key' => ''],
        ])
        ->assertSessionHasNoErrors();

    expect($this->org->fresh()->llm_driver)->toBe('openai');
});

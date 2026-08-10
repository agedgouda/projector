<?php

use App\Models\DismissedOrgRecording;
use App\Models\Organization;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);

    $this->org = Organization::create(['name' => 'Test Org', 'meeting_provider' => 'zoom']);

    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);
});

it('dismisses a recording', function () {
    $this->actingAs($this->admin)
        ->post(route('organizations.dismiss-recording', $this->org), [
            'recording_id' => 'rec-123',
        ])
        ->assertRedirect();

    expect(DismissedOrgRecording::where('organization_id', $this->org->id)
        ->where('recording_id', 'rec-123')
        ->exists())->toBeTrue();
});

it('is idempotent when dismissing the same recording twice', function () {
    $this->actingAs($this->admin)
        ->post(route('organizations.dismiss-recording', $this->org), ['recording_id' => 'rec-123']);
    $this->actingAs($this->admin)
        ->post(route('organizations.dismiss-recording', $this->org), ['recording_id' => 'rec-123']);

    expect(DismissedOrgRecording::where('organization_id', $this->org->id)
        ->where('recording_id', 'rec-123')
        ->count())->toBe(1);
});

it('forbids dismissing a recording for a user without org-admin rights', function () {
    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'member']);

    $this->actingAs($member)
        ->post(route('organizations.dismiss-recording', $this->org), ['recording_id' => 'rec-123'])
        ->assertNotFound();

    expect(DismissedOrgRecording::count())->toBe(0);
});

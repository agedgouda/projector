<?php

use App\Models\AiUsageLog;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TusUpload;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Acme Inc', 'membership_tier' => Organization::TIER_PRO]);
    $this->user = User::factory()->withoutTwoFactor()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);

    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Project', 'client_id' => $this->client->id]);

    $this->actingAs($this->user)->withSession(['active_org_id' => $this->org->id]);
});

function logTranscribedSeconds(Organization $org, int $seconds, string $type = 'transcription', ?\Carbon\Carbon $at = null): void
{
    AiUsageLog::create([
        'organization_id' => $org->id,
        'driver' => 'assemblyai',
        'model' => 'universal-2',
        'type' => $type,
        'audio_seconds' => $seconds,
        'created_at' => $at ?? now(),
    ]);
}

function startRecordingUpload(Project $project, bool $startsRecording = true): \Illuminate\Testing\TestResponse
{
    $metadata = 'filename '.base64_encode('meeting.mp4').',filetype '.base64_encode('video/mp4');

    return test()->post(route('projects.browser-recordings.tus.create', $project), [], [
        'Upload-Length' => '100',
        'Tus-Resumable' => '1.0.0',
        'Upload-Metadata' => $startsRecording ? $metadata.',starts_recording '.base64_encode('true') : $metadata,
    ]);
}

it('pools the tier\'s hours per user across the organization\'s members', function () {
    expect($this->org->transcriptionSecondsLimit())->toBe(20 * 3600);

    $this->org->users()->attach(User::factory()->create()->id, ['role' => 'team-member']);
    $this->org->users()->attach(User::factory()->create()->id, ['role' => 'team-member']);

    expect($this->org->transcriptionSecondsLimit())->toBe(60 * 3600);
});

it('gives each tier its own allowance', function (string $tier, ?int $hours) {
    $this->org->update(['membership_tier' => $tier]);

    expect($this->org->transcriptionSecondsLimit())->toBe($hours === null ? null : $hours * 3600);
})->with([
    'free' => [Organization::TIER_FREE, 5],
    'pro' => [Organization::TIER_PRO, 20],
    'friends & family (unlimited)' => [Organization::TIER_FRIENDS_FAMILY, null],
    'demo' => [Organization::TIER_DEMO, 1],
]);

it('counts only this month\'s transcription, not LLM usage or earlier months', function () {
    logTranscribedSeconds($this->org, 600);
    logTranscribedSeconds($this->org, 900, at: now()->subMonthNoOverflow());
    logTranscribedSeconds($this->org, 300, type: 'llm');
    logTranscribedSeconds(Organization::create(['name' => 'Other']), 1200);

    expect($this->org->currentMonthTranscriptionSeconds())->toBe(600);
});

it('allows new transcription until the allowance is used, then says when it resets', function () {
    logTranscribedSeconds($this->org, 20 * 3600 - 1);
    expect($this->org->transcriptionLimitMessage())->toBeNull();

    logTranscribedSeconds($this->org, 1);

    expect($this->org->transcriptionLimitMessage())
        ->toContain('used all 20 hours of recording transcription included this month')
        ->toContain(now()->addMonthNoOverflow()->startOfMonth()->format('F j'))
        ->and($this->org->atLimits()['transcription'])->toBeTrue();
});

it('never limits an unlimited tier', function () {
    $this->org->update(['membership_tier' => Organization::TIER_FRIENDS_FAMILY]);
    logTranscribedSeconds($this->org, 500 * 3600);

    expect($this->org->transcriptionLimitMessage())->toBeNull();
});

it('refuses to start a recording upload once the allowance is used', function () {
    logTranscribedSeconds($this->org, 20 * 3600);

    startRecordingUpload($this->project)
        ->assertForbidden()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'used all 20 hours'));

    expect(TusUpload::count())->toBe(0);
});

it('still accepts the rest of a capture that was already running when the allowance ran out', function () {
    logTranscribedSeconds($this->org, 20 * 3600);

    startRecordingUpload($this->project, startsRecording: false)->assertCreated();
});

it('starts a recording upload while there is allowance left', function () {
    logTranscribedSeconds($this->org, 3600);

    startRecordingUpload($this->project)->assertCreated();
});

it('shares the limit message with the page once the allowance is used', function () {
    logTranscribedSeconds($this->org, 20 * 3600);

    $this->get(route('organizations.index', ['org' => $this->org->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('orgMembership.at_limit.transcription', true)
            ->where('orgMembership.usage.transcription_seconds_this_month', 20 * 3600)
            ->where('orgMembership.usage.transcription_seconds_limit', 20 * 3600)
            ->where('orgMembership.transcription_limit_message', fn (string $message) => str_contains($message, 'used all 20 hours'))
            ->where('transcriptionAllowance.used_seconds', 20 * 3600)
            ->where('transcriptionAllowance.limit_seconds', 20 * 3600)
        );
});

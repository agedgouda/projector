<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createRecordingWithAudio(array $metadata, \Carbon\Carbon $createdAt): Document
{
    $org = Organization::create(['name' => 'Acme Inc']);
    $client = Client::create([
        'organization_id' => $org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $project = Project::create([
        'name' => 'Test Project',
        'client_id' => $client->id,
    ]);

    $document = $project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Recording',
        'content' => 'Transcript.',
        'processed_at' => now(),
        'metadata' => $metadata,
        'created_at' => $createdAt,
    ]);
    $document->timestamps = false;
    $document->created_at = $createdAt;
    $document->save();

    $dataSize = 100;
    $wavHeader = 'RIFF'.pack('V', 36 + $dataSize).'WAVE'.'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
        .pack('V', 8000).pack('V', 8000).pack('v', 1).pack('v', 8).'data'.pack('V', $dataSize)
        .str_repeat("\x00", $dataSize);
    $document->addMedia(UploadedFile::fake()->createWithContent('meeting.wav', $wavHeader))->toMediaCollection('recording');

    return $document;
}

beforeEach(function () {
    Storage::fake('local');
});

it('deletes audio for unapproved recordings older than the retention window', function () {
    $old = createRecordingWithAudio(
        ['recording_source' => 'mobile_recording', 'audio_status' => 'pending'],
        now()->subDays(40)
    );

    $this->artisan('app:prune-unapproved-recordings')->assertSuccessful();

    expect($old->fresh()->getFirstMedia('recording'))->toBeNull();
});

it('keeps audio for recent unapproved recordings', function () {
    $recent = createRecordingWithAudio(
        ['recording_source' => 'mobile_recording', 'audio_status' => 'pending'],
        now()->subDays(5)
    );

    $this->artisan('app:prune-unapproved-recordings')->assertSuccessful();

    expect($recent->fresh()->getFirstMedia('recording'))->not->toBeNull();
});

it('keeps audio for approved recordings even when old', function () {
    $approved = createRecordingWithAudio(
        ['recording_source' => 'mobile_recording', 'audio_status' => 'approved'],
        now()->subDays(90)
    );

    $this->artisan('app:prune-unapproved-recordings')->assertSuccessful();

    expect($approved->fresh()->getFirstMedia('recording'))->not->toBeNull();
});

it('ignores documents that are not mobile recordings', function () {
    $notARecording = createRecordingWithAudio([], now()->subDays(90));

    $this->artisan('app:prune-unapproved-recordings')->assertSuccessful();

    expect($notARecording->fresh()->getFirstMedia('recording'))->not->toBeNull();
});

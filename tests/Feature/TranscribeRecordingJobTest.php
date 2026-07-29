<?php

use App\Contracts\TranscriptionDriver;
use App\Jobs\ProcessDocumentAI;
use App\Jobs\TranscribeRecording;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createPlaceholderRecording(bool $withMedia = true): Document
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
        'name' => 'Recording — Jul 25, 2026',
        'content' => '',
        'processed_at' => now(),
        'metadata' => ['recording_source' => 'mobile_recording', 'audio_status' => 'pending'],
    ]);

    if ($withMedia) {
        $dataSize = 100;
        $wavHeader = 'RIFF'.pack('V', 36 + $dataSize).'WAVE'.'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
            .pack('V', 8000).pack('V', 8000).pack('v', 1).pack('v', 8).'data'.pack('V', $dataSize)
            .str_repeat("\x00", $dataSize);

        $document->addMedia(UploadedFile::fake()->createWithContent('meeting.wav', $wavHeader))
            ->toMediaCollection('recording');
    }

    return $document;
}

beforeEach(function () {
    Storage::fake('local');
});

it('submits the audio and stores the transcript id on the first run', function () {
    $document = createPlaceholderRecording();

    $driver = Mockery::mock(TranscriptionDriver::class);
    $driver->shouldReceive('submit')->once()->andReturn('transcript-123');

    (new TranscribeRecording($document))->handle($driver);

    expect($document->fresh()->metadata['transcription_id'])->toBe('transcript-123');
});

it('polls again without changing anything while still processing', function () {
    $document = createPlaceholderRecording();
    $document->update(['metadata' => array_merge($document->metadata, ['transcription_id' => 'transcript-123'])]);

    $driver = Mockery::mock(TranscriptionDriver::class);
    $driver->shouldReceive('poll')->once()->with('transcript-123')->andReturn([
        'status' => 'processing',
        'text' => null,
        'error' => null,
    ]);

    (new TranscribeRecording($document))->handle($driver);

    expect($document->fresh()->content)->toBe('');
    expect($document->fresh()->processed_at)->not->toBeNull();
});

it('saves the transcript and dispatches AI processing once completed', function () {
    Queue::fake();

    $document = createPlaceholderRecording();
    $document->update(['metadata' => array_merge($document->metadata, ['transcription_id' => 'transcript-123'])]);

    $driver = Mockery::mock(TranscriptionDriver::class);
    $driver->shouldReceive('poll')->once()->with('transcript-123')->andReturn([
        'status' => 'completed',
        'text' => 'This is the meeting transcript.',
        'error' => null,
    ]);

    (new TranscribeRecording($document))->handle($driver);

    $fresh = $document->fresh();
    expect($fresh->content)->toBe('This is the meeting transcript.');
    expect($fresh->processed_at)->toBeNull();

    Queue::assertPushed(ProcessDocumentAI::class, fn ($job) => $job->document->is($fresh));
});

it('gives up when the transcript comes back empty', function () {
    $document = createPlaceholderRecording();
    $document->update(['metadata' => array_merge($document->metadata, ['transcription_id' => 'transcript-123'])]);

    $driver = Mockery::mock(TranscriptionDriver::class);
    $driver->shouldReceive('poll')->once()->with('transcript-123')->andReturn([
        'status' => 'completed',
        'text' => '',
        'error' => null,
    ]);

    (new TranscribeRecording($document))->handle($driver);

    $fresh = $document->fresh();
    expect($fresh->content)->toBe('');
    expect($fresh->processed_at)->not->toBeNull();
    expect($fresh->metadata['transcription_error'])->toBe('No speech detected in the recording.');
});

it('gives up when the vendor reports an error', function () {
    $document = createPlaceholderRecording();
    $document->update(['metadata' => array_merge($document->metadata, ['transcription_id' => 'transcript-123'])]);

    $driver = Mockery::mock(TranscriptionDriver::class);
    $driver->shouldReceive('poll')->once()->with('transcript-123')->andReturn([
        'status' => 'error',
        'text' => null,
        'error' => 'Audio file could not be decoded.',
    ]);

    (new TranscribeRecording($document))->handle($driver);

    expect($document->fresh()->metadata['transcription_error'])->toBe('Audio file could not be decoded.');
});

it('gives up immediately when there is no recording audio to transcribe', function () {
    $document = createPlaceholderRecording(withMedia: false);

    $driver = Mockery::mock(TranscriptionDriver::class);
    $driver->shouldNotReceive('submit');

    (new TranscribeRecording($document))->handle($driver);

    expect($document->fresh()->metadata['transcription_error'])->toBe('No recording audio found to transcribe.');
});

<?php

use App\Contracts\TranscriptionDriver;
use App\Jobs\ProcessDocumentAI;
use App\Jobs\TranscribeRecording;
use App\Models\AiUsageLog;
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

it('does nothing when another run already got the transcript', function () {
    $document = createPlaceholderRecording();
    $document->update(['content' => 'Already transcribed.']);

    $driver = Mockery::mock(TranscriptionDriver::class);
    $driver->shouldNotReceive('submit');
    $driver->shouldNotReceive('poll');

    (new TranscribeRecording($document))->handle($driver);

    expect($document->fresh()->content)->toBe('Already transcribed.');
});

function completedPoll(string $text, ?array $usage = ['driver' => 'assemblyai', 'model' => 'universal-2', 'audio_seconds' => 3600]): array
{
    return ['status' => 'completed', 'text' => $text, 'error' => null, 'usage' => $usage];
}

it('logs the transcribed audio against the project with its per-hour cost', function () {
    Queue::fake();

    $document = createPlaceholderRecording();
    $document->update(['metadata' => array_merge($document->metadata, ['transcription_id' => 'transcript-123'])]);

    $driver = Mockery::mock(TranscriptionDriver::class);
    $driver->shouldReceive('poll')->once()->andReturn(completedPoll('This is the meeting transcript.'));

    (new TranscribeRecording($document))->handle($driver);

    $log = AiUsageLog::sole();
    $project = $document->project;

    expect($log->type)->toBe('transcription')
        ->and($log->driver)->toBe('assemblyai')
        ->and($log->model)->toBe('universal-2')
        ->and($log->audio_seconds)->toBe(3600)
        ->and($log->project_id)->toBe($project->id)
        ->and($log->client_id)->toBe($project->client_id)
        ->and($log->organization_id)->toBe($project->client->organization_id)
        ->and((float) $log->cost_usd)->toEqualWithDelta(0.17, 0.000001)
        ->and($document->fresh()->metadata['transcription_usage_logged'])->toBe('transcript-123');
});

it('prices an unrecognized model at the fallback rate', function () {
    Queue::fake();

    $document = createPlaceholderRecording();
    $document->update(['metadata' => array_merge($document->metadata, ['transcription_id' => 'transcript-123'])]);

    $driver = Mockery::mock(TranscriptionDriver::class);
    $driver->shouldReceive('poll')->once()->andReturn(completedPoll('Transcript.', ['driver' => 'assemblyai', 'model' => 'some-new-model', 'audio_seconds' => 1800]));

    (new TranscribeRecording($document))->handle($driver);

    expect((float) AiUsageLog::sole()->cost_usd)->toEqualWithDelta(0.115, 0.000001);
});

it('logs usage even when the transcript held no speech, since the audio is still billed', function () {
    $document = createPlaceholderRecording();
    $document->update(['metadata' => array_merge($document->metadata, ['transcription_id' => 'transcript-123'])]);

    $driver = Mockery::mock(TranscriptionDriver::class);
    $driver->shouldReceive('poll')->once()->andReturn(completedPoll(''));

    (new TranscribeRecording($document))->handle($driver);

    expect(AiUsageLog::count())->toBe(1)
        ->and($document->fresh()->metadata['transcription_error'])->toBe('No speech detected in the recording.');
});

it('does not log the same transcript twice when the poll re-runs', function () {
    Queue::fake();

    $document = createPlaceholderRecording();
    $document->update(['metadata' => array_merge($document->metadata, [
        'transcription_id' => 'transcript-123',
        'transcription_usage_logged' => 'transcript-123',
    ])]);

    $driver = Mockery::mock(TranscriptionDriver::class);
    $driver->shouldReceive('poll')->once()->andReturn(completedPoll('Transcript.'));

    (new TranscribeRecording($document))->handle($driver);

    expect(AiUsageLog::count())->toBe(0)
        ->and($document->fresh()->content)->toBe('Transcript.');
});

it('logs a resubmitted transcript separately', function () {
    Queue::fake();

    $document = createPlaceholderRecording();
    $document->update(['metadata' => array_merge($document->metadata, [
        'transcription_id' => 'transcript-456',
        'transcription_usage_logged' => 'transcript-123',
    ])]);

    $driver = Mockery::mock(TranscriptionDriver::class);
    $driver->shouldReceive('poll')->once()->andReturn(completedPoll('Transcript.'));

    (new TranscribeRecording($document))->handle($driver);

    expect(AiUsageLog::count())->toBe(1);
});

it('logs nothing while the transcript is still processing or when the vendor reports no usage', function (array $result) {
    Queue::fake();

    $document = createPlaceholderRecording();
    $document->update(['metadata' => array_merge($document->metadata, ['transcription_id' => 'transcript-123'])]);

    $driver = Mockery::mock(TranscriptionDriver::class);
    $driver->shouldReceive('poll')->once()->andReturn($result);

    (new TranscribeRecording($document))->handle($driver);

    expect(AiUsageLog::count())->toBe(0);
})->with([
    'processing' => [['status' => 'processing', 'text' => null, 'error' => null, 'usage' => null]],
    'error' => [['status' => 'error', 'text' => null, 'error' => 'Bad audio', 'usage' => null]],
    'no usage reported' => [['status' => 'completed', 'text' => 'Transcript.', 'error' => null, 'usage' => null]],
]);

it('reads audio length and model from the AssemblyAI transcript', function () {
    \Illuminate\Support\Facades\Http::fake([
        'api.assemblyai.com/*' => \Illuminate\Support\Facades\Http::response([
            'status' => 'completed',
            'text' => 'Hello.',
            'audio_duration' => 3125.4,
            'speech_model_used' => 'universal-3-5-pro',
        ]),
    ]);

    $result = app(\App\Services\Transcription\Drivers\AssemblyAiTranscriptionDriver::class)->poll('transcript-123');

    expect($result['usage'])->toBe(['driver' => 'assemblyai', 'model' => 'universal-3-5-pro', 'audio_seconds' => 3126]);
});

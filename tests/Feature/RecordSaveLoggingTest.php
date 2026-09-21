<?php

use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Log;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);

    // Inert unless a test switches it on: a model listener that vetoes the save, which makes
    // update() return false without throwing — a save that "succeeds" and stores nothing. (The
    // application is rebuilt for every test, so this listener doesn't outlive it.)
    $GLOBALS['blockDocumentUpdates'] = false;
    Document::updating(fn () => $GLOBALS['blockDocumentUpdates'] ? false : null);

    DocumentTypeDefinition::create(['organization_id' => null, 'key' => 'task', 'label' => 'Task', 'is_task' => true, 'order' => 1]);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $this->client->id]);

    $this->user = User::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);

    $this->document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'A Task',
        'type' => 'task',
        'content' => 'Do it',
        'priority' => 'low',
        'task_status' => 'todo',
        'due_at' => '2026-10-01',
        'processed_at' => now(),
    ]);

    // Each channel points at a file this test owns, so what was logged can be read back.
    $this->saveLog = tempnam(sys_get_temp_dir(), 'record-saves');
    $this->mainLog = tempnam(sys_get_temp_dir(), 'main-log');
    config([
        'logging.channels.record_saves' => ['driver' => 'single', 'path' => $this->saveLog, 'level' => 'debug'],
        'logging.channels.test_main' => ['driver' => 'single', 'path' => $this->mainLog, 'level' => 'debug'],
        'logging.default' => 'test_main',
    ]);
    Log::forgetChannel('record_saves');
    Log::forgetChannel('test_main');
});

afterEach(function () {
    @unlink($this->saveLog);
    @unlink($this->mainLog);
});

function saveLogContents(): string
{
    return (string) file_get_contents(test()->saveLog);
}

function mainLogContents(): string
{
    return (string) file_get_contents(test()->mainLog);
}

function attributesUrl(): string
{
    return route('projects.documents.updateAttributes', [test()->project, test()->document]);
}

it('logs a save on arrival and again with its outcome, under the id the browser sent', function () {
    $this->actingAs($this->user)
        ->withHeaders(['X-Save-Id' => 'save-abc-123', 'X-Client-Queued-Saves' => '3'])
        ->patchJson(attributesUrl(), ['priority' => 'high'])
        ->assertOk();

    $log = saveLogContents();

    expect($log)->toContain('received')
        ->and($log)->toContain('responded')
        ->and($log)->toContain('"save_id":"save-abc-123"')
        ->and($log)->toContain($this->document->id)
        ->and($log)->toContain('"priority":"high"')
        ->and($log)->toContain('"status":200')
        ->and($log)->toContain('"client_queued_saves":"3"')
        ->and($log)->toContain('"duration_ms"');
});

it('logs a save from a browser that sent no id under one it makes up', function () {
    $this->actingAs($this->user)->patchJson(attributesUrl(), ['priority' => 'high'])->assertOk();

    expect(saveLogContents())->toMatch('/"save_id":"[0-9a-f-]{36}"/');
});

it('records what a save changed, with the value before and after', function () {
    $this->actingAs($this->user)
        ->patchJson(attributesUrl(), ['priority' => 'high', 'task_status' => 'done'])
        ->assertOk();

    $log = saveLogContents();

    expect($log)->toContain('applied')
        ->and($log)->toContain('"noop":false')
        ->and($log)->toContain('"changed":{')
        ->and($log)->toContain('"priority":"high"')
        ->and($log)->toContain('"previous":{')
        ->and($log)->toContain('"priority":"low"')
        ->and($log)->toContain('"task_status":"done"')
        ->and($log)->toContain('"task_status":"todo"');
});

it('flags a save that changed nothing as a no-op', function () {
    $this->actingAs($this->user)->patchJson(attributesUrl(), ['priority' => 'low'])->assertOk();

    expect(saveLogContents())->toContain('"noop":true');
});

it('logs a rejected save with the validation errors, in both logs', function () {
    $this->actingAs($this->user)
        ->withHeaders(['X-Save-Id' => 'save-rejected'])
        ->patchJson(attributesUrl(), ['task_status' => 'not-a-real-column'])
        ->assertUnprocessable();

    foreach ([saveLogContents(), mainLogContents()] as $log) {
        expect($log)->toContain('failed')
            ->and($log)->toContain('save-rejected')
            ->and($log)->toContain('"status":422')
            ->and($log)->toContain('task_status');
    }
});

it('logs a save from someone who has no access to the project', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->withHeaders(['X-Save-Id' => 'save-outsider'])
        ->patchJson(attributesUrl(), ['priority' => 'high']);

    expect(saveLogContents())->toContain('save-outsider')
        ->and(saveLogContents())->toMatch('/"status":40[34]/')
        ->and(mainLogContents())->toContain('save-outsider');

    expect($this->document->fresh()->priority)->toBe('low');
});

it('logs the id of a save whose request is redirected instead of answered', function () {
    $this->withHeaders(['X-Save-Id' => 'save-logged-out'])
        ->patch(attributesUrl(), ['priority' => 'high'])
        ->assertRedirect();

    expect(saveLogContents())->toContain('save-logged-out')
        ->and(saveLogContents())->toContain('redirect_to');
});

it('does not report a save as successful when the change did not reach the database', function () {
    $GLOBALS['blockDocumentUpdates'] = true;

    $this->actingAs($this->user)
        ->withHeaders(['X-Save-Id' => 'save-lost'])
        ->patchJson(attributesUrl(), ['priority' => 'high'])
        ->assertStatus(500)
        ->assertExactJson(['message' => 'This change could not be saved. Please try again.']);

    $GLOBALS['blockDocumentUpdates'] = false;

    foreach ([saveLogContents(), mainLogContents()] as $log) {
        expect($log)->toContain('did not persist')
            ->and($log)->toContain('save-lost')
            ->and($log)->toContain('"unsaved":{"priority":{"expected":"high","saved":"low"}}');
    }

    expect($this->document->fresh()->priority)->toBe('low');
});

it('tells an Inertia save that did not persist so through the form errors', function () {
    $GLOBALS['blockDocumentUpdates'] = true;

    $this->actingAs($this->user)
        ->patch(attributesUrl(), ['priority' => 'high'])
        ->assertRedirect()
        ->assertSessionHasErrors('save');

    $GLOBALS['blockDocumentUpdates'] = false;

    expect(saveLogContents())->toContain('did not persist');
});

it('checks a date by its day, so a date sent as a plain date is not reported as unsaved', function () {
    $this->actingAs($this->user)
        ->patchJson(attributesUrl(), ['due_at' => '2026-11-15'])
        ->assertOk();

    expect(saveLogContents())->not->toContain('did not persist')
        ->and(substr((string) $this->document->fresh()->due_at, 0, 10))->toBe('2026-11-15');
});

it('checks clearing a value, so a date cleared to null is not reported as unsaved', function () {
    $this->actingAs($this->user)
        ->patchJson(attributesUrl(), ['due_at' => null])
        ->assertOk();

    expect(saveLogContents())->not->toContain('did not persist')
        ->and($this->document->fresh()->due_at)->toBeNull();
});

it('logs a tag save and the tags it attached', function () {
    $design = Category::create(['project_id' => $this->project->id, 'name' => 'Design', 'color' => 'pink']);

    $this->actingAs($this->user)
        ->withHeaders(['X-Save-Id' => 'save-tags'])
        ->putJson(route('projects.documents.updateCategories', [$this->project, $this->document]), ['category_ids' => [$design->id]])
        ->assertOk();

    expect(saveLogContents())->toContain('save-tags')
        ->and(saveLogContents())->toContain('tags_attached')
        ->and(saveLogContents())->toContain($design->id);
});

it('logs a full document edit without writing its content into the log', function () {
    $this->actingAs($this->user)
        ->postJson(route('projects.documents.update', [$this->project, $this->document]), [
            'content' => 'A private note that must not be logged',
            '_method' => 'put',
        ])
        ->assertRedirect();

    expect(saveLogContents())->toContain('content_length')
        ->and(saveLogContents())->not->toContain('A private note that must not be logged');
});

it('records a change made by something other than a person editing, and what wrote it', function () {
    // A queued job, import, or command changing a task — the kind of write that could quietly
    // put a value back.
    $this->document->update(['task_status' => 'done']);

    $log = saveLogContents();

    expect($log)->toContain('changed by other writer')
        ->and($log)->toContain('console/queue')
        ->and($log)->toContain('"task_status":"done"')
        ->and($log)->toContain('"task_status":"todo"');
});

it('does not log an edit twice when the save endpoint has already logged it', function () {
    $this->actingAs($this->user)->patchJson(attributesUrl(), ['priority' => 'high'])->assertOk();

    expect(saveLogContents())->not->toContain('changed by other writer');
});

it('logs the browser\'s report under the id of the save it is about', function () {
    $this->postJson(route('client-logs.record-save'), ['event' => 'save-failed', 'save_id' => 'save-abandoned'])->assertNoContent();

    expect(saveLogContents())->toContain('"save_id":"save-abandoned"');
});

it('takes the browser\'s report of a save that never reached the server', function () {
    $this->postJson(route('client-logs.record-save'), [
        'event' => 'save-failed',
        'save_id' => 'save-offline',
        'method' => 'patch',
        'url' => '/projects/1/documents/2/attributes',
        'data' => ['priority' => 'high', 'content' => 'A private note'],
        'status' => null,
        'code' => 'ERR_NETWORK',
        'message' => 'Network Error',
        'duration_ms' => 4321,
        'online' => false,
        'queued_saves' => 2,
    ])->assertNoContent();

    foreach ([saveLogContents(), mainLogContents()] as $log) {
        expect($log)->toContain('client-reported: save-failed')
            ->and($log)->toContain('ERR_NETWORK')
            ->and($log)->toContain('Network Error')
            ->and($log)->toContain('"online":false')
            ->and($log)->toContain('"duration_ms":4321')
            ->and($log)->toContain('"priority":"high"')
            ->and($log)->toContain('content_length')
            ->and($log)->not->toContain('A private note');
    }
});

it('does not log the routes it has no interest in', function () {
    $this->actingAs($this->user)->get(route('projects.show', $this->project));

    expect(saveLogContents())->toBe('');
});

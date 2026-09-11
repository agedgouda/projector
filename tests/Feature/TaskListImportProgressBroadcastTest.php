<?php

use App\Events\TaskListImportProgress;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Test Org']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Acme Corp',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create([
        'name' => 'Website Redesign',
        'client_id' => $this->client->id,
    ]);
    $this->importDocument = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Imported tasks',
        'type' => 'task_list_import',
        'content' => '[]',
    ]);
});

it('broadcasts only on the triggering user\'s private channel', function () {
    $event = new TaskListImportProgress($this->importDocument, 1, 1, 'running', triggeredByUserId: 42);

    $channelNames = collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all();

    expect($channelNames)->toEqual(['private-user.42']);
});

it('broadcasts on no channels at all when there is no triggering user', function () {
    $event = new TaskListImportProgress($this->importDocument, 1, 1, 'running');

    expect($event->broadcastOn())->toEqual([]);
});

it('registers the user.{id} channel authorization rule, restricted to that user\'s own id', function () {
    $callback = Broadcast::getChannels()->get('user.{id}');

    expect($callback)->not->toBeNull();

    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    expect($callback($user, (string) $user->id))->toBeTrue();
    expect($callback($user, (string) $otherUser->id))->toBeFalse();
});

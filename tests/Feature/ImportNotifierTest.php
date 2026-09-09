<?php

use App\Mail\ImportResultMail;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SlackUserIdentity;
use App\Models\SlackWorkspace;
use App\Models\User;
use App\Services\Import\ImportNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Test Org']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $this->client->id]);
    $this->user = User::factory()->create();
    $this->notifier = new ImportNotifier;
});

it('posts in-channel via slack when a channel reply is given, regardless of any linked identity', function () {
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200)]);

    $this->notifier->notify($this->user, 'Test message', $this->project, [
        'bot_token' => 'xoxb-fake-token',
        'channel_id' => 'C123',
    ]);

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
        && $request['channel'] === 'C123'
        && $request['text'] === 'Test message');
});

it('dms the user via slack when they have a linked identity for the project org and no channel is given', function () {
    $workspace = SlackWorkspace::factory()->create(['organization_id' => $this->org->id, 'team_id' => 'T123', 'bot_access_token' => 'xoxb-fake-token']);
    SlackUserIdentity::factory()->create(['user_id' => $this->user->id, 'slack_team_id' => 'T123', 'slack_user_id' => 'U999']);

    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200)]);

    $this->notifier->notify($this->user, 'Test message', $this->project);

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
        && $request['channel'] === 'U999'
        && $request['text'] === 'Test message');

    expect($workspace)->not->toBeNull();
});

it('emails the user when no channel is given and they have no linked slack identity', function () {
    Mail::fake();

    $this->notifier->notify($this->user, 'Test message', $this->project);

    Mail::assertSent(ImportResultMail::class, fn ($mail) => $mail->hasTo($this->user->email) && $mail->resultMessage === 'Test message');
});

it('emails the user when the project org has no slack workspace at all', function () {
    Mail::fake();
    SlackUserIdentity::factory()->create(['user_id' => $this->user->id, 'slack_team_id' => 'T999']);

    $this->notifier->notify($this->user, 'Test message', $this->project);

    Mail::assertSent(ImportResultMail::class);
});

it('emails the user when they have a slack identity for a different team than the project org uses', function () {
    Mail::fake();
    SlackWorkspace::factory()->create(['organization_id' => $this->org->id, 'team_id' => 'T123']);
    SlackUserIdentity::factory()->create(['user_id' => $this->user->id, 'slack_team_id' => 'T-different']);

    $this->notifier->notify($this->user, 'Test message', $this->project);

    Mail::assertSent(ImportResultMail::class);
});

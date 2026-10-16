<?php

use App\Enums\EmailDeliveryStatus;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Message;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

const RESEND_TEST_SECRET = 'whsec_dGVzdC13ZWJob29rLXNlY3JldA==';

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme Corp']);
    $this->orgAdmin = User::factory()->create();
    $this->org->users()->attach($this->orgAdmin->id, ['role' => 'org-admin']);

    config(['services.resend.webhook_secret' => RESEND_TEST_SECRET]);
});

/**
 * Stands in for Laravel's Resend transport, which stamps each sent message with the id
 * Resend's API returned for it.
 */
function useFakeResendTransport(string ...$emailIds): void
{
    Mail::extend('fake-resend', fn () => new class($emailIds) extends AbstractTransport
    {
        /** @param  list<string>  $emailIds */
        public function __construct(private array $emailIds)
        {
            parent::__construct();
        }

        protected function doSend(SentMessage $message): void
        {
            $original = $message->getOriginalMessage();

            if ($original instanceof Message) {
                $original->getHeaders()->addHeader('X-Resend-Email-ID', array_shift($this->emailIds) ?? 'none');
            }
        }

        public function __toString(): string
        {
            return 'fake-resend';
        }
    });

    config(['mail.mailers.fake-resend' => ['transport' => 'fake-resend'], 'mail.default' => 'fake-resend']);
}

function postResendEvent(array $payload, ?int $timestamp = null, ?string $secret = RESEND_TEST_SECRET): \Illuminate\Testing\TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $id = 'msg_'.uniqid();
    $timestamp ??= time();
    $key = base64_decode(substr((string) $secret, strlen('whsec_')));
    $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

    return test()->call('POST', route('resend.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_SVIX_ID' => $id,
        'HTTP_SVIX_TIMESTAMP' => (string) $timestamp,
        'HTTP_SVIX_SIGNATURE' => "v1,bogus v1,{$signature}",
    ], $body);
}

function trackedInvitation(array $attributes = []): OrganizationInvitation
{
    return OrganizationInvitation::create(array_merge([
        'organization_id' => test()->org->id,
        'email' => 'invitee@example.com',
        'first_name' => 'In',
        'last_name' => 'Vitee',
        'role' => 'team-member',
        'token' => 'tok'.uniqid(),
        'expires_at' => now()->addDays(7),
        'resend_email_id' => 'email-123',
        'email_status' => EmailDeliveryStatus::Sent,
        'email_status_at' => now(),
    ], $attributes));
}

it('records the resend email id and a sent status when an invitation is sent', function () {
    useFakeResendTransport('email-abc');

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invite', $this->org), ['first_name' => 'New', 'last_name' => 'User', 'email' => 'new@example.com', 'role' => 'team-member'])
        ->assertRedirect();

    $invitation = OrganizationInvitation::sole();

    expect($invitation->resend_email_id)->toBe('email-abc')
        ->and($invitation->email_status)->toBe(EmailDeliveryStatus::Sent)
        ->and($invitation->email_status_at)->not->toBeNull();
});

it('starts tracking the new email when an invitation is resent', function () {
    useFakeResendTransport('email-new');
    $invitation = trackedInvitation(['email_status' => EmailDeliveryStatus::Bounced, 'email_status_detail' => 'Mailbox full']);

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invitations.resend', [$this->org, $invitation]))
        ->assertRedirect();

    $invitation->refresh();

    expect($invitation->resend_email_id)->toBe('email-new')
        ->and($invitation->email_status)->toBe(EmailDeliveryStatus::Sent)
        ->and($invitation->email_status_detail)->toBeNull();
});

it('starts tracking the new email when an invitation is edited', function () {
    useFakeResendTransport('email-edited');
    $invitation = trackedInvitation();

    $this->actingAs($this->orgAdmin)
        ->put(route('organizations.invitations.update', [$this->org, $invitation]), ['first_name' => 'In', 'last_name' => 'Vitee', 'email' => 'other@example.com', 'role' => 'team-member'])
        ->assertRedirect();

    expect($invitation->refresh()->resend_email_id)->toBe('email-edited');
});

it('leaves no status when the mailer is not resend', function () {
    config(['mail.default' => 'array']);

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.invite', $this->org), ['first_name' => 'New', 'last_name' => 'User', 'email' => 'new@example.com', 'role' => 'team-member']);

    $invitation = OrganizationInvitation::sole();

    expect($invitation->resend_email_id)->toBeNull()
        ->and($invitation->email_status)->toBeNull();
});

it('updates the invitation status from a signed webhook event', function (string $type, EmailDeliveryStatus $status) {
    $invitation = trackedInvitation();

    postResendEvent(['type' => $type, 'created_at' => '2026-10-16T12:00:00.000Z', 'data' => ['email_id' => 'email-123']])
        ->assertNoContent();

    $invitation->refresh();

    expect($invitation->email_status)->toBe($status)
        ->and($invitation->email_status_at?->toIso8601String())->toBe('2026-10-16T12:00:00+00:00');
})->with([
    'delivered' => ['email.delivered', EmailDeliveryStatus::Delivered],
    'delayed' => ['email.delivery_delayed', EmailDeliveryStatus::DeliveryDelayed],
    'opened' => ['email.opened', EmailDeliveryStatus::Opened],
    'clicked' => ['email.clicked', EmailDeliveryStatus::Clicked],
    'complained' => ['email.complained', EmailDeliveryStatus::Complained],
    'suppressed' => ['email.suppressed', EmailDeliveryStatus::Suppressed],
]);

it('stores the reason for a bounce or failure', function (array $data, EmailDeliveryStatus $status, string $reason) {
    $invitation = trackedInvitation();

    postResendEvent(['type' => 'email.'.$status->value, 'data' => array_merge(['email_id' => 'email-123'], $data)])
        ->assertNoContent();

    expect($invitation->refresh()->email_status)->toBe($status)
        ->and($invitation->email_status_detail)->toBe($reason);
})->with([
    'bounce' => [['bounce' => ['message' => 'Mailbox does not exist', 'type' => 'Permanent']], EmailDeliveryStatus::Bounced, 'Mailbox does not exist'],
    'failure' => [['failed' => ['reason' => 'reached_daily_quota']], EmailDeliveryStatus::Failed, 'reached_daily_quota'],
]);

it('does not let an out-of-order event downgrade the status', function () {
    $invitation = trackedInvitation(['email_status' => EmailDeliveryStatus::Opened]);

    postResendEvent(['type' => 'email.delivered', 'data' => ['email_id' => 'email-123']])->assertNoContent();

    expect($invitation->refresh()->email_status)->toBe(EmailDeliveryStatus::Opened);
});

it('acknowledges events for other emails or without delivery meaning', function (array $payload) {
    $invitation = trackedInvitation();

    postResendEvent($payload)->assertNoContent();

    expect($invitation->refresh()->email_status)->toBe(EmailDeliveryStatus::Sent);
})->with([
    'unknown email' => [['type' => 'email.delivered', 'data' => ['email_id' => 'someone-else']]],
    'scheduled' => [['type' => 'email.scheduled', 'data' => ['email_id' => 'email-123']]],
    'contact event' => [['type' => 'contact.created', 'data' => ['id' => 'c1']]],
    'no email id' => [['type' => 'email.delivered', 'data' => []]],
]);

it('rejects unsigned, forged, or stale webhook calls', function (?int $timestamp, ?string $secret) {
    $invitation = trackedInvitation();

    postResendEvent(['type' => 'email.delivered', 'data' => ['email_id' => 'email-123']], $timestamp, $secret)
        ->assertUnauthorized();

    expect($invitation->refresh()->email_status)->toBe(EmailDeliveryStatus::Sent);
})->with([
    'wrong secret' => [null, 'whsec_'.base64_encode('not-the-secret')],
    'stale timestamp' => [time() - 600, RESEND_TEST_SECRET],
]);

it('rejects a webhook call with no signature headers', function () {
    $this->postJson(route('resend.webhook'), ['type' => 'email.delivered', 'data' => ['email_id' => 'email-123']])
        ->assertUnauthorized();
});

it('refuses webhook calls when no secret is configured', function () {
    config(['services.resend.webhook_secret' => null]);

    postResendEvent(['type' => 'email.delivered', 'data' => ['email_id' => 'email-123']])
        ->assertServerError();
});

it('shares each invitation status with the organization page', function () {
    trackedInvitation(['email_status' => EmailDeliveryStatus::Bounced, 'email_status_detail' => 'Mailbox does not exist']);

    $response = $this->actingAs($this->orgAdmin)->get(route('organizations.index', ['org' => $this->org->id]));

    expect($response->inertiaProps('invitations.0.email_status'))->toBe('bounced')
        ->and($response->inertiaProps('invitations.0.email_status_detail'))->toBe('Mailbox does not exist')
        ->and($response->inertiaProps('invitations.0.email_status_at'))->not->toBeNull();
});

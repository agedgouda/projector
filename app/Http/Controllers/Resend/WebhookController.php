<?php

namespace App\Http\Controllers\Resend;

use App\Enums\EmailDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\OrganizationInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Receives Resend's email events (already signature-checked by VerifyResendSignature) and
 * records the delivery status on the invitation whose email they're about. Events for any
 * other email, or that say nothing about delivery, are acknowledged and ignored, so Resend
 * doesn't keep retrying them.
 */
class WebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        $status = EmailDeliveryStatus::fromResendEvent($request->string('type')->toString());
        $emailId = $request->input('data.email_id');

        if ($status === null || ! is_string($emailId) || $emailId === '') {
            return response()->noContent();
        }

        $invitation = OrganizationInvitation::where('resend_email_id', $emailId)->first();

        if ($invitation) {
            $invitation->recordEmailStatus($status, $this->detail($request), $this->occurredAt($request));
        }

        return response()->noContent();
    }

    /**
     * The human-readable reason Resend gives for a bounce or failure, if any.
     */
    private function detail(Request $request): ?string
    {
        foreach (['data.bounce.message', 'data.failed.reason'] as $key) {
            $value = $request->input($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function occurredAt(Request $request): Carbon
    {
        $createdAt = $request->input('created_at');

        try {
            return is_string($createdAt) ? Carbon::parse($createdAt) : now();
        } catch (\Throwable) {
            return now();
        }
    }
}

<?php

namespace App\Enums;

/**
 * Where an email stands with Resend, as reported by its webhook events. Events can arrive out
 * of order, so each status has a rank and a later event only replaces an earlier status of an
 * equal or lower rank — a late "delivered" never hides an "opened", and nothing hides a bounce
 * or spam complaint.
 */
enum EmailDeliveryStatus: string
{
    case Sent = 'sent';
    case DeliveryDelayed = 'delivery_delayed';
    case Delivered = 'delivered';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Bounced = 'bounced';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
    case Complained = 'complained';

    /**
     * Maps a Resend webhook event type (e.g. "email.delivered") to a status, or null for
     * events that say nothing about delivery (e.g. "email.scheduled", "email.received").
     */
    public static function fromResendEvent(string $eventType): ?self
    {
        if (! str_starts_with($eventType, 'email.')) {
            return null;
        }

        return self::tryFrom(substr($eventType, strlen('email.')));
    }

    public function rank(): int
    {
        return match ($this) {
            self::Sent => 1,
            self::DeliveryDelayed => 2,
            self::Delivered => 3,
            self::Opened => 4,
            self::Clicked => 5,
            self::Bounced, self::Failed, self::Suppressed => 6,
            self::Complained => 7,
        };
    }

    public function supersedes(?self $current): bool
    {
        return $current === null || $this->rank() >= $current->rank();
    }
}

<?php

namespace App\Models;

use App\Enums\EmailDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property \Illuminate\Support\Carbon $expires_at
 * @property string|null $resend_email_id
 * @property EmailDeliveryStatus|null $email_status
 * @property \Illuminate\Support\Carbon|null $email_status_at
 * @property string|null $email_status_detail
 */
class OrganizationInvitation extends Model
{
    public $timestamps = false;

    protected $fillable = ['organization_id', 'email', 'first_name', 'last_name', 'role', 'token', 'expires_at',
        'resend_email_id', 'email_status', 'email_status_at', 'email_status_detail'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'email_status' => EmailDeliveryStatus::class,
            'email_status_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function documentAssignments(): HasMany
    {
        return $this->hasMany(Document::class, 'pending_assignee_invitation_id');
    }

    /**
     * Applies a status reported by Resend's webhook, unless the invitation already has a
     * status that outranks it (see EmailDeliveryStatus).
     */
    public function recordEmailStatus(EmailDeliveryStatus $status, ?string $detail, \DateTimeInterface $at): void
    {
        if (! $status->supersedes($this->email_status)) {
            return;
        }

        $this->update([
            'email_status' => $status,
            'email_status_at' => $at,
            'email_status_detail' => $detail !== null ? mb_substr($detail, 0, 500) : null,
        ]);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}

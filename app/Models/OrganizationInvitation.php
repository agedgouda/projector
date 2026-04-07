<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationInvitation extends Model
{
    public $timestamps = false;

    protected $fillable = ['organization_id', 'email', 'role', 'token', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
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

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}

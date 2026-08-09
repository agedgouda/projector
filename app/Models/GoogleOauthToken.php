<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string $google_email
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon $expires_at
 * @property string|null $scopes
 * @property User $user
 */
class GoogleOauthToken extends Model
{
    /** @use HasFactory<\Database\Factories\GoogleOauthTokenFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'google_email',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->subSeconds(60)->isPast();
    }
}

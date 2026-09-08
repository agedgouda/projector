<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $organization_id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $sent_date
 * @property Organization $organization
 * @property User $user
 */
class SlackDigestSend extends Model
{
    /** @use HasFactory<\Database\Factories\SlackDigestSendFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'user_id',
        'sent_date',
    ];

    protected function casts(): array
    {
        return [
            'sent_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

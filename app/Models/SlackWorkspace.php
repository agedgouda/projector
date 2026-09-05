<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $team_id
 * @property string $team_name
 * @property string $bot_access_token
 * @property string $bot_user_id
 * @property string|null $scopes
 * @property int|null $installed_by_user_id
 * @property Organization $organization
 * @property User|null $installedBy
 */
class SlackWorkspace extends Model
{
    /** @use HasFactory<\Database\Factories\SlackWorkspaceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'team_id',
        'team_name',
        'bot_access_token',
        'bot_user_id',
        'scopes',
        'installed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'bot_access_token' => 'encrypted',
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
    public function installedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by_user_id');
    }

    /**
     * @return HasMany<SlackChannelBinding, $this>
     */
    public function channelBindings(): HasMany
    {
        return $this->hasMany(SlackChannelBinding::class);
    }
}

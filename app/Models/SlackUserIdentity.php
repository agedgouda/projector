<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a Slack identity (a specific user within a specific Slack workspace) to the Projector
 * user it belongs to, so Slack-triggered actions (slash commands, message shortcuts) can be
 * attributed to a real account instead of a generic "Slack" actor. Deliberately not scoped to an
 * Organization — a Projector user may belong to several organizations, each with its own
 * connected Slack workspace, and needs one identity link per workspace (see the unique
 * constraints on the migration: one Slack user per team can only map to one Projector user, and
 * one Projector user can only link one identity per team).
 *
 * @property string $id
 * @property int $user_id
 * @property string $slack_team_id
 * @property string $slack_user_id
 * @property string|null $slack_username
 * @property User $user
 */
class SlackUserIdentity extends Model
{
    /** @use HasFactory<\Database\Factories\SlackUserIdentityFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'slack_team_id',
        'slack_user_id',
        'slack_username',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

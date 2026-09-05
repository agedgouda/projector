<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $slack_workspace_id
 * @property string $channel_id
 * @property string $channel_name
 * @property string $project_id
 * @property SlackWorkspace $slackWorkspace
 * @property Project $project
 */
class SlackChannelBinding extends Model
{
    /** @use HasFactory<\Database\Factories\SlackChannelBindingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'slack_workspace_id',
        'channel_id',
        'channel_name',
        'project_id',
    ];

    /**
     * @return BelongsTo<SlackWorkspace, $this>
     */
    public function slackWorkspace(): BelongsTo
    {
        return $this->belongsTo(SlackWorkspace::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}

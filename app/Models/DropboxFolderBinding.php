<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $dropbox_workspace_id
 * @property string $folder_id
 * @property string $folder_path
 * @property string $project_id
 * @property DropboxWorkspace $dropboxWorkspace
 * @property Project $project
 */
class DropboxFolderBinding extends Model
{
    /** @use HasFactory<\Database\Factories\DropboxFolderBindingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'dropbox_workspace_id',
        'folder_id',
        'folder_path',
        'project_id',
    ];

    /**
     * @return BelongsTo<DropboxWorkspace, $this>
     */
    public function dropboxWorkspace(): BelongsTo
    {
        return $this->belongsTo(DropboxWorkspace::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}

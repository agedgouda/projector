<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * organization_id is unique — one Dropbox connection per org. Unlike SlackWorkspace, there's no
 * equivalent to Slack's per-user identity link (SlackUserIdentity): a Dropbox file's uploader
 * isn't reliably knowable (see App\Services\Import\ImportNotifier / ImportDropboxFile's own
 * docblock for why), so every import through a bound folder attributes to installed_by_user_id
 * — whoever connected the account — instead.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $account_id
 * @property string $account_name
 * @property string $access_token
 * @property string $refresh_token
 * @property \Illuminate\Support\Carbon|null $access_token_expires_at
 * @property string|null $cursor
 * @property int|null $installed_by_user_id
 * @property Organization $organization
 * @property User|null $installedBy
 */
class DropboxWorkspace extends Model
{
    /** @use HasFactory<\Database\Factories\DropboxWorkspaceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'account_id',
        'account_name',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'cursor',
        'installed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
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
     * @return HasMany<DropboxFolderBinding, $this>
     */
    public function folderBindings(): HasMany
    {
        return $this->hasMany(DropboxFolderBinding::class);
    }
}

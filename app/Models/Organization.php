<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Organization extends Model implements HasMedia
{
    use HasFactory, HasUuids, InteractsWithMedia;

    const TIER_FREE = 'free';

    const TIER_PRO = 'pro';

    const TIER_FRIENDS_FAMILY = 'friends_family';

    /** @var array<string, array{users: ?int, clients: ?int, projects: ?int, ai_docs_per_month: ?int}> */
    const TIER_LIMITS = [
        self::TIER_FREE => [
            'users' => 1,
            'clients' => 1,
            'projects' => 1,
            'ai_docs_per_month' => 25,
        ],
        self::TIER_PRO => [
            'users' => null,
            'clients' => null,
            'projects' => null,
            'ai_docs_per_month' => 100,
        ],
        self::TIER_FRIENDS_FAMILY => [
            'users' => null,
            'clients' => null,
            'projects' => null,
            'ai_docs_per_month' => null,
        ],
    ];

    protected $fillable = [
        'name', 'slug', 'normalized_name', 'website',
        'membership_tier', 'planned_user_count',
        'llm_driver', 'llm_config', 'vector_driver', 'vector_config',
        'meeting_provider', 'meeting_config',
    ];

    protected function casts(): array
    {
        return [
            'llm_config' => 'encrypted:array',
            'vector_config' => 'encrypted:array',
            'meeting_config' => 'encrypted:array',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        // Use 'saving' to handle both Create and Update automatically
        static::saving(function ($organization) {
            if ($organization->isDirty('name')) {
                $organization->slug = Str::slug($organization->name);
                $organization->normalized_name = self::normalize($organization->name);
            }
        });
    }

    /**
     * Centralized logic to update LLM/Vector configurations.
     * This handles merging existing keys if the new ones are empty.
     */
    public function fillConfiguration(string $type, ?string $driver, array $input): void
    {
        $driverField = "{$type}_driver";
        $configField = "{$type}_config";

        // Logic for 'same' driver or null drivers
        if (! $driver || $driver === 'same') {
            $this->{$driverField} = ($driver === 'same') ? 'same' : null;
            $this->{$configField} = null;

            return;
        }

        $existing = $this->{$configField} ?? [];

        // Merge input with existing data (specifically for the 'key')
        $newConfig = [
            'model' => $input['model'] ?? ($existing['model'] ?? null),
            'host' => $input['host'] ?? ($existing['host'] ?? null),
            'key' => ! empty($input['key']) ? $input['key'] : ($existing['key'] ?? null),
        ];

        $this->{$driverField} = $driver;
        $this->{$configField} = array_filter($newConfig, fn ($v) => ! is_null($v) && $v !== '');
    }

    /**
     * Scope to filter organizations based on user role.
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $user->hasRole('super-admin')
            ? $query
            : $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
    }

    /**
     * Duplicate Prevention Logic
     */
    public static function normalize(string $name): string
    {
        $name = strtolower($name);
        $suffixes = [' inc', ' corp', ' ltd', ' limited', ' llc', ' incorporated'];
        $clean = str_replace($suffixes, '', $name);

        return Str::slug(trim($clean));
    }

    /**
     * Override the global AI driver config with this organization's stored settings.
     * Call this before resolving LlmDriver or VectorDriver from the container.
     */
    public function applyDriverConfig(): void
    {
        if ($this->llm_driver) {
            $driver = $this->llm_driver;
            $config = $this->llm_config ?? [];

            config(['services.llm_driver' => $driver]);

            if (! empty($config['key'])) {
                config(["services.{$driver}.key" => $config['key']]);
            }
            if (! empty($config['model'])) {
                config(["services.{$driver}.model" => $config['model']]);
            }
            if (! empty($config['host'])) {
                config(["services.{$driver}.host" => $config['host']]);
            }
        }

        if ($this->vector_driver) {
            config(['services.vector_driver' => $this->vector_driver]);

            // 'same' reuses the LLM driver's config — no extra overrides needed
            if ($this->vector_driver !== 'same') {
                $config = $this->vector_config ?? [];

                if (! empty($config['key'])) {
                    config(["services.{$this->vector_driver}.key" => $config['key']]);
                }
                if (! empty($config['model'])) {
                    config(["services.{$this->vector_driver}.model" => $config['model']]);
                }
                if (! empty($config['host'])) {
                    config(["services.{$this->vector_driver}.host" => $config['host']]);
                }
            }
        }
    }

    /* --- UI Helpers --- */

    public function llmConfigForForm(): array
    {
        $config = $this->llm_config ?? [];

        return [
            'model' => $config['model'] ?? '',
            'host' => $config['host'] ?? '',
            'has_key' => ! empty($config['key']),
        ];
    }

    public function vectorConfigForForm(): array
    {
        $config = $this->vector_config ?? [];

        return [
            'model' => $config['model'] ?? '',
            'host' => $config['host'] ?? '',
            'has_key' => ! empty($config['key']),
        ];
    }

    public function meetingConfigForForm(): array
    {
        $config = $this->meeting_config ?? [];

        return [
            // Zoom / Teams
            'account_id' => $config['account_id'] ?? '',
            'tenant_id' => $config['tenant_id'] ?? '',
            'client_id' => $config['client_id'] ?? '',
            'has_client_secret' => ! empty($config['client_secret']),
            // Google Meet (service account)
            'service_account_email' => $config['service_account_email'] ?? '',
            'impersonate_email' => $config['impersonate_email'] ?? '',
            'has_private_key' => ! empty($config['private_key']),
            // Slack
            'has_bot_token' => ! empty($config['bot_token']),
        ];
    }

    public function fillMeetingConfiguration(?string $provider, array $input): void
    {
        if (! $provider) {
            $this->meeting_provider = null;
            $this->meeting_config = null;

            return;
        }

        $existing = $this->meeting_config ?? [];

        $newConfig = [
            // Zoom / Teams
            'account_id' => $input['account_id'] ?? ($existing['account_id'] ?? null),
            'tenant_id' => $input['tenant_id'] ?? ($existing['tenant_id'] ?? null),
            'client_id' => $input['client_id'] ?? ($existing['client_id'] ?? null),
            'client_secret' => ! empty($input['client_secret']) ? $input['client_secret'] : ($existing['client_secret'] ?? null),
            // Google Meet
            'service_account_email' => $input['service_account_email'] ?? ($existing['service_account_email'] ?? null),
            'impersonate_email' => $input['impersonate_email'] ?? ($existing['impersonate_email'] ?? null),
            'private_key' => ! empty($input['private_key']) ? $input['private_key'] : ($existing['private_key'] ?? null),
            // Slack
            'bot_token' => ! empty($input['bot_token']) ? $input['bot_token'] : ($existing['bot_token'] ?? null),
        ];

        $this->meeting_provider = $provider;
        $this->meeting_config = array_filter($newConfig, fn ($v) => ! is_null($v) && $v !== '');
    }

    /* --- Membership --- */

    public function tierLimits(): array
    {
        return self::TIER_LIMITS[$this->membership_tier] ?? self::TIER_LIMITS[self::TIER_FREE];
    }

    public function tierLabel(): string
    {
        return match ($this->membership_tier) {
            self::TIER_PRO => 'Pro',
            self::TIER_FRIENDS_FAMILY => 'Friends & Family',
            default => 'Free',
        };
    }

    public function currentMonthAiUsage(): int
    {
        return AiUsageLog::where('organization_id', $this->id)
            ->where('type', 'llm')
            ->whereYear('created_at', Carbon::now()->year)
            ->whereMonth('created_at', Carbon::now()->month)
            ->count();
    }

    public function currentUserCount(): int
    {
        return $this->users()->count();
    }

    public function currentClientCount(): int
    {
        return $this->clients()->count();
    }

    public function currentProjectCount(): int
    {
        return \App\Models\Project::whereHas('client', fn ($q) => $q->where('organization_id', $this->id))->count();
    }

    /**
     * @return array{users: bool, clients: bool, projects: bool, ai_docs: bool}
     */
    public function atLimits(): array
    {
        $limits = $this->tierLimits();

        return [
            'users' => $limits['users'] !== null && $this->currentUserCount() >= $limits['users'],
            'clients' => $limits['clients'] !== null && $this->currentClientCount() >= $limits['clients'],
            'projects' => $limits['projects'] !== null && $this->currentProjectCount() >= $limits['projects'],
            'ai_docs' => $limits['ai_docs_per_month'] !== null && $this->currentMonthAiUsage() >= $limits['ai_docs_per_month'],
        ];
    }

    /* --- Media --- */

    public function getLogoUrlAttribute(): ?string
    {
        $url = $this->getFirstMediaUrl('logo', 'preview');

        return $url !== '' ? $url : null;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(150)
            ->height(150)
            ->nonQueued();

        $this->addMediaConversion('preview')
            ->width(400)
            ->height(400)
            ->nonQueued();
    }

    /* --- Relationships --- */

    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function projectTypes(): HasMany
    {
        return $this->hasMany(ProjectType::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function orgDocuments(): HasMany
    {
        return $this->hasMany(OrgDocument::class);
    }
}

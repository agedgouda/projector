<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class Organization extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'slug', 'normalized_name', 'website',
        'llm_driver', 'llm_config', 'vector_driver', 'vector_config'
    ];

    protected function casts(): array
    {
        return [
            'llm_config' => 'encrypted:array',
            'vector_config' => 'encrypted:array',
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
        if (!$driver || $driver === 'same') {
            $this->{$driverField} = ($driver === 'same') ? 'same' : null;
            $this->{$configField} = null;
            return;
        }

        $existing = $this->{$configField} ?? [];

        // Merge input with existing data (specifically for the 'key')
        $newConfig = [
            'model' => $input['model'] ?? ($existing['model'] ?? null),
            'host'  => $input['host']  ?? ($existing['host']  ?? null),
            'key'   => !empty($input['key']) ? $input['key'] : ($existing['key'] ?? null),
        ];

        $this->{$driverField} = $driver;
        $this->{$configField} = array_filter($newConfig, fn($v) => !is_null($v) && $v !== '');
    }

    /**
     * Scope to filter organizations based on user role.
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $user->hasRole('super-admin')
            ? $query
            : $query->whereHas('users', fn($q) => $q->where('users.id', $user->id));
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

    /* --- Relationships --- */

    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function clients(): HasMany { return $this->hasMany(Client::class); }
    public function projectTypes(): HasMany { return $this->hasMany(ProjectType::class); }
}

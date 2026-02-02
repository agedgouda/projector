<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Organization extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'slug', 'normalized_name', 'website'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($organization) {
            // Auto-generate slug if missing
            if (empty($organization->slug)) {
                $organization->slug = \Illuminate\Support\Str::slug($organization->name);
            }

            // Auto-generate normalized name if missing
            if (empty($organization->normalized_name)) {
                $organization->normalized_name = \Illuminate\Support\Str::upper($organization->name);
            }
        });
    }

    /**
     * Relationship: Users in this Organization
     */
    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Relationship: Clients owned by this Organization
     */
    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    /**
     * Duplicate Prevention Logic
     * Strips common suffixes to find the "core" name.
     */
    public static function normalize(string $name): string
    {
        $name = strtolower($name);
        $suffixes = [' inc', ' corp', ' ltd', ' limited', ' llc', ' incorporated'];
        $clean = str_replace($suffixes, '', $name);
        return Str::slug(trim($clean));
    }
}

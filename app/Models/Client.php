<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Client extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_name',
        'contact_name',
        'contact_phone',
    ];

    // Explicitly define the primary key type
    protected $keyType = 'string';
    public $incrementing = false;

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function scopeVisibleTo($query, $user)
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        // A Client is visible if the user is attached via the pivot table
        return $query->whereHas('users', function ($q) use ($user) {
            $q->where('users.id', $user->id);
        });
    }


}

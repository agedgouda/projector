<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
}

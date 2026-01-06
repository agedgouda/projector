<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class aiTemplate extends Model
{
    protected $fillable = [
        'name',
        'system_prompt',
        'user_prompt',
    ];
}

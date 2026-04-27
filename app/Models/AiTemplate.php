<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiTemplate extends Model
{
    protected $fillable = [
        'name',
        'type',
        'system_prompt',
        'user_prompt',
    ];
}

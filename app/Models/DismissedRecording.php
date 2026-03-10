<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DismissedRecording extends Model
{
    protected $fillable = ['project_id', 'recording_id'];
}

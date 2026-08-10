<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DismissedOrgRecording extends Model
{
    protected $fillable = ['organization_id', 'recording_id'];
}

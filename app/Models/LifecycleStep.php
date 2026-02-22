<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LifecycleStep extends Model
{
    /** @use HasFactory<\Database\Factories\LifecycleStepFactory> */
    use HasFactory;

    protected $fillable = ['project_type_id', 'order', 'label', 'description', 'color'];

    public function projectType(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class);
    }
}

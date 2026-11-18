<?php

namespace App\Models;

use Database\Factories\LifecycleStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LifecycleStep extends Model
{
    /** @use HasFactory<LifecycleStepFactory> */
    use HasFactory;

    protected $fillable = ['lifecycle_template_id', 'order', 'label', 'description', 'color'];

    /**
     * @return BelongsTo<LifecycleTemplate, $this>
     */
    public function lifecycleTemplate(): BelongsTo
    {
        return $this->belongsTo(LifecycleTemplate::class);
    }
}

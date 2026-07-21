<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LifecycleStep extends Model
{
    /** @use HasFactory<\Database\Factories\LifecycleStepFactory> */
    use HasFactory;

    protected $fillable = ['project_type_id', 'lifecycle_template_id', 'order', 'label', 'description', 'color'];

    /**
     * @deprecated kept only until project_type_id is dropped from lifecycle_steps in the contract
     * phase — use lifecycleTemplate() for anything new.
     */
    public function projectType(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class);
    }

    /**
     * @return BelongsTo<LifecycleTemplate, $this>
     */
    public function lifecycleTemplate(): BelongsTo
    {
        return $this->belongsTo(LifecycleTemplate::class);
    }
}

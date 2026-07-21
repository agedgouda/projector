<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $project_type_id
 * @property string $from_key
 * @property string $to_key
 * @property int|null $ai_template_id
 * @property bool $single_output
 * @property int $order
 */
class WorkflowStep extends Model
{
    /** @use HasFactory<\Database\Factories\WorkflowStepFactory> */
    use HasFactory;

    protected $fillable = ['project_type_id', 'from_key', 'to_key', 'ai_template_id', 'single_output', 'order'];

    protected function casts(): array
    {
        return [
            'single_output' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ProjectType, $this>
     */
    public function projectType(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class);
    }

    /**
     * @return BelongsTo<AiTemplate, $this>
     */
    public function aiTemplate(): BelongsTo
    {
        return $this->belongsTo(AiTemplate::class);
    }
}

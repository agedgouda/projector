<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One page visit or action a demo visitor made inside their sandbox, by route name only.
 * Recorded by App\Http\Middleware\RecordDemoActivity.
 *
 * @property int $id
 * @property int $demo_session_id
 * @property string $event
 * @property string $method
 * @property Carbon $created_at
 */
class DemoEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'demo_session_id',
        'event',
        'method',
    ];

    /**
     * @return BelongsTo<DemoSession, $this>
     */
    public function demoSession(): BelongsTo
    {
        return $this->belongsTo(DemoSession::class);
    }
}

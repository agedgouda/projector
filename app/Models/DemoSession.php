<?php

namespace App\Models;

use Database\Factories\DemoSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One guest's journey through the public demo: landed on /demo (created_at), started a sandbox,
 * was turned away, left for sign-up, registered. Kept permanently for analytics and holds no
 * personal data — see the create_demo_sessions_table migration.
 *
 * @property int $id
 * @property int|null $referral_visit_id
 * @property string|null $ref
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $country
 * @property string|null $device_type
 * @property string|null $browser
 * @property string|null $referrer_host
 * @property Carbon|null $started_at
 * @property Carbon|null $rejected_at
 * @property string|null $rejected_reason
 * @property Carbon|null $exited_at
 * @property Carbon|null $registered_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DemoSession extends Model
{
    /** @use HasFactory<DemoSessionFactory> */
    use HasFactory;

    /** Session key holding the id of the demo session recorded for the current browser session. */
    public const SESSION_KEY = 'demo_session_id';

    public const REJECTED_BUSY = 'busy';

    public const REJECTED_VERIFICATION = 'verification';

    protected $fillable = [
        'referral_visit_id',
        'ref',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'country',
        'device_type',
        'browser',
        'referrer_host',
        'started_at',
        'rejected_at',
        'rejected_reason',
        'exited_at',
        'registered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'rejected_at' => 'datetime',
            'exited_at' => 'datetime',
            'registered_at' => 'datetime',
        ];
    }

    /**
     * The demo session for the current browser session, if one was recorded.
     */
    public static function current(): ?self
    {
        if (! app()->bound('request') || ! request()->hasSession()) {
            return null;
        }

        $id = request()->session()->get(self::SESSION_KEY);

        return is_int($id) ? self::query()->find($id) : null;
    }

    /**
     * @return BelongsTo<ReferralVisit, $this>
     */
    public function referralVisit(): BelongsTo
    {
        return $this->belongsTo(ReferralVisit::class);
    }

    /**
     * @return HasMany<DemoEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(DemoEvent::class);
    }
}

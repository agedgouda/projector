<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One guest's first arrival at /login or /register via a marketing link carrying ?ref=<code>,
 * recorded at most once per session by App\Http\Middleware\RecordReferralVisit. Stores no
 * personal data — see the create_referral_visits_table migration.
 *
 * @property int $id
 * @property string $ref
 * @property string $landing_path
 * @property string|null $country
 * @property string|null $device_type
 * @property string|null $browser
 * @property string|null $referrer_host
 * @property Carbon|null $converted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ReferralVisit extends Model
{
    /** @use HasFactory<\Database\Factories\ReferralVisitFactory> */
    use HasFactory;

    /** Session key holding the id of the visit recorded for the current session. */
    public const SESSION_KEY = 'referral_visit_id';

    protected $fillable = [
        'ref',
        'landing_path',
        'country',
        'device_type',
        'browser',
        'referrer_host',
        'converted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
        ];
    }
}

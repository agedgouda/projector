<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * We no longer register any media conversions (see App\Models\Project, Client, and
     * Organization), but existing media rows still carry generated_conversions flags (e.g.
     * {"thumb":true,"preview":true}) from before. Spatie's own Media model always defines a
     * previewUrl accessor that checks hasGeneratedConversion('preview') whenever a Media
     * record is serialized — with the stale flag still set, it tries to resolve a conversion
     * that no longer exists and throws InvalidConversion. Resetting the column here removes
     * the last reference to conversions that were deleted from the app.
     */
    public function up(): void
    {
        DB::table('media')->update(['generated_conversions' => '{}']);
    }

    public function down(): void
    {
        // Not reversible — the original per-conversion flags aren't recoverable, and the
        // conversions they referred to no longer exist in the app either way.
    }
};

<?php

namespace App\Console\Commands;

use App\Jobs\SendSlackDigestToAdmin;
use App\Models\Organization;
use App\Models\SlackDigestSend;
use App\Models\SlackUserIdentity;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendSlackDailyDigest extends Command
{
    /**
     * The console command runs hourly (see routes/console.php) rather than once a day, since
     * there's no single UTC time that's 8am for every admin's own timezone — each run only
     * acts on admins for whom it's currently this hour, in their own timezone.
     */
    private const SEND_HOUR = 8;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-slack-daily-digest';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send each org-admin a Slack DM of tasks due today (or the next few upcoming deliverables) across their organization\'s projects, once per admin per local calendar day.';

    public function handle(): int
    {
        $organizations = Organization::whereHas('slackWorkspace')->with('slackWorkspace')->get();

        foreach ($organizations as $organization) {
            $admins = $organization->users()->wherePivot('role', 'org-admin')->get();

            foreach ($admins as $admin) {
                $this->maybeDispatchFor($organization, $admin);
            }
        }

        return self::SUCCESS;
    }

    private function maybeDispatchFor(Organization $organization, User $admin): void
    {
        $localNow = Carbon::now($admin->effectiveTimezone());

        if ($localNow->hour !== self::SEND_HOUR) {
            return;
        }

        // Checked here (not just left to the job) so an admin who's never linked Slack doesn't
        // get a SlackDigestSend row recorded every day for a digest that was never actually
        // sendable — the job re-checks this same thing itself in case the identity is unlinked
        // between this dispatch decision and the job actually running.
        $hasSlackIdentity = SlackUserIdentity::where('user_id', $admin->id)
            ->where('slack_team_id', $organization->slackWorkspace?->team_id)
            ->exists();

        if (! $hasSlackIdentity) {
            return;
        }

        // firstOrCreate + wasRecentlyCreated is this command's idempotency check, not just a
        // convenience — the unique index on (organization_id, user_id, sent_date) is what
        // actually guarantees at most one digest per admin per org per local day even if this
        // command's hourly run ever overlaps itself.
        $sendRecord = SlackDigestSend::firstOrCreate([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'sent_date' => $localNow->toDateString(),
        ]);

        if (! $sendRecord->wasRecentlyCreated) {
            return;
        }

        SendSlackDigestToAdmin::dispatch($organization, $admin);
    }
}

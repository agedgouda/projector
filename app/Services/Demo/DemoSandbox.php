<?php

namespace App\Services\Demo;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * A temporary, fully working Projector account for one demo visitor: their own organization
 * with three client projects (see DemoSamples), each holding a meeting transcript that the
 * real AI pipeline turns into Meeting Notes as soon as it's created, plus that meeting's team
 * as members so tasks have people to assign. Demo AI runs use the cheaper services.demo.llm_model
 * with the system OpenAI key. Everything is deleted after LIFETIME_HOURS.
 */
class DemoSandbox
{
    public const LIFETIME_HOURS = 24;

    /** Reserved (RFC 2606) so no demo account can ever receive real email. */
    public const EMAIL_DOMAIN = 'demo.projecthq.invalid';

    public function __construct(private readonly DemoSamples $samples) {}

    /**
     * @return array{user: User, project: Project}
     */
    public function create(): array
    {
        $token = Str::lower(Str::random(12));
        $expiresAt = now()->addHours(self::LIFETIME_HOURS);

        [$visitor, $projects] = DB::transaction(function () use ($token, $expiresAt): array {
            // Created without model events so Organization's saving hook doesn't derive the slug
            // from the shared display name, which every sandbox would then collide on.
            $organization = new Organization([
                'name' => 'Demo Agency',
                'slug' => "demo-{$token}",
                'normalized_name' => "demo-{$token}",
                'membership_tier' => Organization::TIER_DEMO,
                'llm_driver' => 'openai',
                'llm_config' => ['model' => config()->string('services.demo.llm_model')],
            ]);
            Organization::withoutEvents(fn (): bool => $organization->save());

            $visitor = $this->createUser('Demo', 'Visitor', "visitor-{$token}", $expiresAt);
            $organization->users()->attach($visitor->id, ['role' => 'org-admin']);

            $teammateIds = [];
            $projects = [];

            foreach (DemoSamples::SAMPLES as $id => $sample) {
                $client = Client::create([
                    'organization_id' => $organization->id,
                    'company_name' => $sample['client'],
                    'contact_name' => $sample['contact'],
                    'industry' => $sample['industry'],
                ]);

                $projects[$id] = Project::create([
                    'client_id' => $client->id,
                    'name' => $sample['project'],
                    'description' => $sample['description'],
                    'description_quality' => 'good',
                ]);

                foreach ($sample['team'] as $name) {
                    [$firstName, $lastName] = explode(' ', $name, 2) + [1 => ''];
                    $teammate = $this->createUser($firstName, $lastName, Str::slug($name).'-'.$token, $expiresAt);
                    $teammateIds[$teammate->id] = ['role' => 'team-member'];
                }
            }

            $organization->users()->attach($teammateIds);
            $visitor->favoriteProjects()->attach(array_map(fn (Project $project): string => $project->id, array_values($projects)));

            return [$visitor, $projects];
        });

        // After the commit, so DocumentObserver's automatic Transcription -> Meeting Notes run
        // (a queued job) can see the rows it needs.
        foreach ($projects as $id => $project) {
            $project->documents()->create([
                'type' => config('workflow.intake_key'),
                'name' => DemoSamples::SAMPLES[$id]['title'],
                'content' => $this->samples->notesHtml($id),
                'creator_id' => $visitor->id,
            ]);
        }

        return ['user' => $visitor, 'project' => reset($projects)];
    }

    /**
     * Deletes every sandbox older than LIFETIME_HOURS.
     *
     * @return int The number of sandboxes deleted.
     */
    public function pruneExpired(): int
    {
        $organizationIds = Organization::query()
            ->where('membership_tier', Organization::TIER_DEMO)
            ->where('created_at', '<', now()->subHours(self::LIFETIME_HOURS))
            ->pluck('id');

        if ($organizationIds->isNotEmpty()) {
            // Clients and project types only null out their organization on delete, so they go
            // first; deleting a client cascades to its projects and their documents.
            Client::query()->whereIn('organization_id', $organizationIds)->delete();
            ProjectType::query()->whereIn('organization_id', $organizationIds)->delete();
            Organization::query()->whereIn('id', $organizationIds)->delete();
        }

        User::query()->whereNotNull('demo_expires_at')->where('demo_expires_at', '<', now())->delete();

        return $organizationIds->count();
    }

    /**
     * Whether an outgoing email is addressed to a demo account, which must never be sent.
     */
    public static function isAddressedToDemoAccount(RawMessage $message): bool
    {
        if (! $message instanceof Email) {
            return false;
        }

        $recipients = [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()];

        return collect($recipients)->contains(
            fn (Address $address): bool => str_ends_with(strtolower($address->getAddress()), '@'.self::EMAIL_DOMAIN),
        );
    }

    private function createUser(string $firstName, string $lastName, string $localPart, \DateTimeInterface $expiresAt): User
    {
        $user = new User;
        $user->forceFill([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $localPart.'@'.self::EMAIL_DOMAIN,
            'password' => Str::random(40),
            'email_verified_at' => now(),
            'privacy_policy_accepted_at' => now(),
            'terms_accepted_at' => now(),
            'demo_expires_at' => $expiresAt,
        ])->save();

        return $user;
    }
}

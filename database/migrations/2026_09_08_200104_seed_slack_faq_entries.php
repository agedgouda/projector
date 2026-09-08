<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds user-facing FAQ entries for the usage-oriented half of docs/slack-app-setup.md
     * (Steps 4-10 — connecting a workspace onward) — not the app-level setup steps (creating
     * the Slack app, the manifest, env vars), which stay in that doc since they're a one-time
     * deploy concern rather than something an org-admin or team member would search the FAQ
     * for. Editable afterward from the /faq admin UI like any other entry, so this is a
     * starting point, not the source of truth going forward.
     */
    public function up(): void
    {
        $now = now();
        $rows = [];

        foreach ($this->faqs() as $index => $faq) {
            $rows[] = [
                ...$faq,
                'category' => 'Slack',
                'order' => ($index + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('faqs')->insert($rows);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('faqs')->where('category', 'Slack')->delete();
    }

    /**
     * @return list<array{question: string, answer: string, keywords: string}>
     */
    private function faqs(): array
    {
        return [
            [
                'question' => 'How do I connect my organization\'s Slack workspace to Projector?',
                'answer' => "Go to that organization's settings page (as an org-admin) and click \"Connect Slack Workspace\" on the Configuration tab. Approve the install on Slack's consent screen, and the page will show the connected workspace's name, its bound channels, and a form to bind more channels to projects.",
                'keywords' => 'slack, connect, workspace, install, setup',
            ],
            [
                'question' => 'How do I link my own Slack account so actions I take are attributed to me?',
                'answer' => 'Go to Settings > Integrations and click "Connect Slack Account". This is separate from the organization-level workspace connection above, and works for any team member, not just org-admins. It only works if the Slack workspace you sign in with has already been connected to an organization you belong to (see the previous question). You can link identities for more than one workspace if you belong to multiple organizations.',
                'keywords' => 'slack, link, identity, account, attribution',
            ],
            [
                'question' => 'How do I create a task from Slack?',
                'answer' => 'In a channel that\'s bound to a project, run "/task" followed by a description, e.g. "/task follow up with the client about the contract by Friday, high priority". Projector uses AI to pull out a title, description, assignee, due date, priority, and tag, then posts the created task in the channel. You need to have linked your Slack identity first (see the previous question).',
                'keywords' => 'slack, task, slash command, create',
            ],
            [
                'question' => 'How do I create an event from Slack?',
                'answer' => 'Same as creating a task, but with "/events" instead — e.g. "/events team offsite next Thursday". Projector pulls out a title, description, start/due dates, and a tag. Unlike tasks, events aren\'t assigned to a person.',
                'keywords' => 'slack, event, slash command, create',
            ],
            [
                'question' => 'Can I create a task or event from an existing Slack message instead of retyping it?',
                'answer' => 'Yes — hover the message, click "More actions" (the ⋯ icon), and choose "Create Task" or "Create Event". A modal opens pre-filled with the message\'s text, which you can edit before submitting. The result posts in the channel once it\'s created.',
                'keywords' => 'slack, shortcut, message action, create task, create event',
            ],
            [
                'question' => 'What is the Slack daily digest, and how do I set it up?',
                'answer' => 'Every org-admin who has linked their Slack identity automatically gets a Slack DM once a day, at 8am in their own local time, listing tasks due that day across their organization\'s projects (or the next 5 upcoming deliverables if nothing\'s due today). The only setup needed is setting your timezone on Settings > Profile — it defaults to UTC. If you administer more than one organization, you\'ll get a separate DM for each.',
                'keywords' => 'slack, digest, daily, timezone, notifications',
            ],
            [
                'question' => 'How do I import a task or event list by uploading a file in Slack?',
                'answer' => 'Drop a CSV, TXT, XLSX, or XLS file straight into a channel that\'s bound to a project. Projector downloads it, figures out automatically whether it\'s a task list, an event list, or a mix of both, and imports it — then replies in the channel with how many records were created. If it can\'t confidently tell what the file is, it\'s added to a "Needs Review" queue on the Import Wizard page (under Import in the sidebar) instead of guessing, where you can finish the mapping by hand.',
                'keywords' => 'slack, import, file, upload, csv, spreadsheet, task list, event list',
            ],
            [
                'question' => 'Can I connect the same Slack workspace to more than one Projector organization?',
                'answer' => 'Yes — for example, an agency running several client organizations that all live in the agency\'s own Slack workspace. Each organization gets its own connection and its own channel bindings; which organization a Slack message or file belongs to is always resolved from the specific channel it happened in, never assumed from the workspace alone. A linked Slack identity works the same way across every organization connected to that workspace — link once, and it\'s recognized in all of them you belong to.',
                'keywords' => 'slack, multiple organizations, shared workspace, agency',
            ],
        ];
    }
};

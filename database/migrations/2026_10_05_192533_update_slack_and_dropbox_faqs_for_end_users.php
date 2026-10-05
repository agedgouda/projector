<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The FAQ is for regular users, not whoever deploys Projector — app creation, credentials,
     * .env and local-testing steps live in docs/dropbox-app-setup.md only.
     *
     * @var list<string>
     */
    private const REMOVED_DROPBOX_QUESTIONS = [
        'How do I create the Dropbox app for Projector, and what permissions does it need?',
        'Where do I find the Dropbox app credentials Projector needs?',
        'What environment variables does Projector need for Dropbox, and how do I test the connection locally?',
    ];

    private const REPORT_QUESTION = 'How do I get a task report or event calendar from Slack?';

    /**
     * Same "only if still un-customized" guard as
     * 2026_09_09_232505_update_dropbox_bind_folder_faq_for_dropdown.php — an admin who already
     * edited a row from the /faq page keeps their own wording.
     */
    public function up(): void
    {
        foreach ($this->answerUpdates() as $update) {
            DB::table('faqs')
                ->where('category', $update['category'])
                ->where('question', $update['question'])
                ->where('answer', $update['old'])
                ->update(['answer' => $update['new'], 'updated_at' => now()]);
        }

        DB::table('faqs')
            ->where('category', 'Dropbox')
            ->whereIn('question', self::REMOVED_DROPBOX_QUESTIONS)
            ->delete();

        if (DB::table('faqs')->where('category', 'Slack')->where('question', self::REPORT_QUESTION)->doesntExist()) {
            DB::table('faqs')->insert([
                'category' => 'Slack',
                'question' => self::REPORT_QUESTION,
                'answer' => 'In a channel that\'s bound to a project, run "/report" followed by what you want in plain English, and the bot uploads the file into the channel. Examples: "/report" (every task, as Excel), "/report my tasks that are in progress", "/report Penny\'s high priority tasks due next week as a PDF", "/report what got done last month, csv", "/report events" (the event calendar as a PDF), "/report marketing events in October as excel", or "/report tasks and events" for both files. Tasks can be filtered by assignee (a name, "me"/"my", or unassigned), status, priority, tags, sub-project, and a due or done date range; events by tag, sub-project, and dates only. The format is Excel unless you ask for CSV or PDF, except events, which default to a PDF month calendar. The upload lists the filters that were applied so you can check it understood you. If it can\'t match something you asked for (a person who isn\'t in the organization, a status the project doesn\'t have), it tells you instead of handing back a broader report. You need to have linked your Slack account first (see "How do I link my own Slack account so actions I take are attributed to me?"), and for a private channel the bot has to be invited with "/invite @Projector".',
                'keywords' => 'slack, report, slash command, export, pdf, excel, csv, calendar, filter',
                'order' => 55,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->answerUpdates() as $update) {
            DB::table('faqs')
                ->where('category', $update['category'])
                ->where('question', $update['question'])
                ->where('answer', $update['new'])
                ->update(['answer' => $update['old'], 'updated_at' => now()]);
        }

        DB::table('faqs')->where('category', 'Slack')->where('question', self::REPORT_QUESTION)->delete();

        if (DB::table('faqs')->where('category', 'Dropbox')->whereIn('question', self::REMOVED_DROPBOX_QUESTIONS)->doesntExist()) {
            $now = now();

            DB::table('faqs')->insert(array_map(fn (array $row) => [
                ...$row,
                'category' => 'Dropbox',
                'created_at' => $now,
                'updated_at' => $now,
            ], $this->removedDropboxRows()));
        }
    }

    /**
     * @return list<array{category: string, question: string, old: string, new: string}>
     */
    private function answerUpdates(): array
    {
        return [
            [
                'category' => 'Slack',
                'question' => 'How do I connect my organization\'s Slack workspace to Projector?',
                'old' => 'Go to that organization\'s settings page (as an org-admin) and click "Connect Slack Workspace" on the Configuration tab. Approve the install on Slack\'s consent screen, and the page will show the connected workspace\'s name, its bound channels, and a form to bind more channels to projects.',
                'new' => 'An org-admin opens the organization\'s Configuration tab and clicks "Connect" next to Slack, then approves the install on Slack\'s consent screen. Once Slack shows as Connected, click the arrow next to it to expand the section: it shows the connected workspace\'s name, its bound channels, and an "Add A Channel" form for binding a channel to a project. Public channels can be picked directly. A private channel only appears in the list after you invite the bot into it by running "/invite @Projector" in that channel.',
            ],
            [
                'category' => 'Slack',
                'question' => 'What is the Slack daily digest, and how do I set it up?',
                'old' => 'Every org-admin who has linked their Slack identity automatically gets a Slack DM once a day, at 8am in their own local time, listing tasks due that day across their organization\'s projects (or the next 5 upcoming deliverables if nothing\'s due today). The only setup needed is setting your timezone on Settings > Profile — it defaults to UTC. If you administer more than one organization, you\'ll get a separate DM for each.',
                'new' => 'Every org-admin who has linked their Slack identity automatically gets a Slack DM once a day, at 8am in their own local time, listing open tasks due that day across their organization\'s projects. If nothing is due that day, it lists the 5 open tasks with the earliest due dates instead, so anything overdue comes first. The only setup needed is setting your timezone on Settings > Profile — it defaults to UTC. If you administer more than one organization, you\'ll get a separate DM for each.',
            ],
            [
                'category' => 'Slack',
                'question' => 'How do I import a task or event list by uploading a file in Slack?',
                'old' => 'Drop a CSV, TXT, XLSX, XLS, or DOCX file straight into a channel that\'s bound to a project. A spreadsheet (CSV/TXT/XLSX/XLS): Projector figures out automatically whether it\'s a task list, an event list, or a mix of both — the first time your project sees a given column layout, it\'s added to a "Needs Review" queue on the Import Wizard page (under Import in the sidebar) instead of importing right away, so a human confirms the mapping once; after that, the same column layout imports immediately every time. A Word document (DOCX) has no column layout to recognize, so it goes one of two ways instead: tag it yourself (see the next question) and it files immediately with no review needed, or — if you don\'t — it goes to that same "Needs Review" queue with an AI-proposed starting point: a task and/or event pass when the content genuinely supports one, or, when it doesn\'t (a meeting transcription, a plain write-up), filing the whole thing as-is under whichever of the project\'s own document types best fits. Either way, a human reviewing it can change the proposed type to anything in the project\'s catalog before confirming. The bot always replies in the channel with what happened and a link to review or see the result.',
                'new' => 'Drop a CSV, TXT, XLSX, XLS, or DOCX file straight into a channel that\'s bound to a project. A spreadsheet (CSV/TXT/XLSX/XLS): Projector figures out automatically whether it\'s a task list, an event list, or a mix of both — the first time your project sees a given column layout, it\'s added to a "Needs Review" queue on the Import Wizard page (under Import in the sidebar) instead of importing right away, so a human confirms the mapping once; after that, the same column layout imports immediately every time. Spreadsheets over 5,000 rows are too large to import this way; use the Import Wizard instead. A Word document (DOCX) has no column layout to recognize, so it goes one of two ways instead: tag it yourself (see the next question) and it files immediately with no review needed, or — if you don\'t — it goes to that same "Needs Review" queue with an AI-proposed starting point: a task and/or event pass when the content genuinely supports one, or, when it doesn\'t (a meeting transcription, a plain write-up), filing the whole thing as-is under whichever of the project\'s own document types best fits. Either way, a human reviewing it can change the proposed type to anything in the project\'s catalog before confirming. The bot always replies in the channel with what happened and a link to review or see the result. You need to have linked your Slack account first, and other file types (images, PDFs, etc.) are ignored.',
            ],
            [
                'category' => 'Dropbox',
                'question' => 'How do I connect my organization\'s Dropbox account to Projector?',
                'old' => 'Go to that organization\'s settings page (as an org-admin), Configuration tab, and click "Connect Dropbox". Approve on Dropbox\'s consent screen, and the panel will show the connected account\'s name, its bound folders, and a form to bind more.',
                'new' => 'An org-admin opens the organization\'s Configuration tab and clicks "Connect" next to Dropbox, then approves on Dropbox\'s consent screen. Once Dropbox shows as Connected, click the arrow next to it to expand the section: it shows the connected account\'s name, its bound folders, and an "Add A Folder" form for binding more.',
            ],
            [
                'category' => 'Dropbox',
                'question' => 'How do I bind a Dropbox folder to a project?',
                'old' => 'Once Dropbox is connected, pick a folder and a project from the "Add A Folder" form on the organization\'s Configuration tab — same picker experience as Slack channels. The folder dropdown lists every top-level folder in the connected account; a nested subfolder isn\'t listed and can\'t be bound directly.',
                'new' => 'Once Dropbox is connected, an org-admin expands the Dropbox section on the organization\'s Configuration tab and picks a folder and a project from the "Add A Folder" form — same picker experience as Slack channels. The folder dropdown lists every top-level folder in the connected account that isn\'t already bound; a nested subfolder isn\'t listed and can\'t be bound directly.',
            ],
            [
                'category' => 'Dropbox',
                'question' => 'How do I force what type a document gets imported as from Dropbox?',
                'old' => 'Drop the file into a subfolder named after the document type\'s short code (e.g. "/Client Intake/meeting-notes/standup.docx" files as Meeting Notes) — no "#" needed, since choosing a folder to drop a file into is already a deliberate act. Only the immediate subfolder counts; a file nested two levels deep isn\'t tagged this way. This is the Dropbox equivalent of Slack\'s #tag override (see "How do I force what type a document gets imported as in Slack?") — Dropbox files have no accompanying message to put a #tag in, so the subfolder name stands in for it.',
                'new' => 'Drop the file into a subfolder named after the document type\'s short code (e.g. "/Client Intake/meeting-notes/standup.docx" files as Meeting Notes) — no "#" needed, since choosing a folder to drop a file into is already a deliberate act. Only the immediate subfolder counts; a file nested two levels deep isn\'t tagged this way. This applies to Word documents (DOCX) only, and as in Slack, "task" and "event" subfolders don\'t force a type. This is the Dropbox equivalent of Slack\'s #tag override (see "How do I force what type a document gets imported as in Slack?") — Dropbox files have no accompanying message to put a #tag in, so the subfolder name stands in for it.',
            ],
        ];
    }

    /**
     * @return list<array{question: string, answer: string, keywords: string, order: int}>
     */
    private function removedDropboxRows(): array
    {
        return [
            [
                'question' => self::REMOVED_DROPBOX_QUESTIONS[0],
                'answer' => <<<'TEXT'
                    1. Go to dropbox.com/developers/apps and click "Create app".
                    2. Choose "Scoped access".
                    3. Choose "Full Dropbox" access (not "App folder") — a project can bind any existing folder in the connected account, not just one Dropbox creates specifically for this app.
                    4. Name the app (e.g. "Projector") and click "Create app".

                    Permissions (on the app's Permissions tab, then click Submit to save):
                    - account_info.read — to read the connected account's name for display.
                    - files.metadata.read — to list what changed in a bound folder.
                    - files.content.read — to download an imported file's actual content.

                    OAuth redirect URI (Settings tab, under "OAuth 2"):
                    https://projecthq.app/organizations/dropbox/callback

                    Webhook URI (Settings tab, under "Webhooks"):
                    https://projecthq.app/dropbox/events

                    The moment you add the webhook URI, Dropbox sends a one-time verification request (a GET with a challenge parameter) and expects it echoed back as plain text — Projector already implements this, so it should verify successfully as soon as the URI is reachable.

                    If you change scopes after users have already connected: a previously-issued token only has whatever scopes existed at authorization time. Reconnecting from the organization's settings page again re-authorizes and overwrites the stored tokens with the full current scope set.

                    For local development, projecthq.app in the URLs above needs to be a Herd Share URL instead — see the next question.
                    TEXT,
                'keywords' => 'dropbox, app, create, setup, permissions, scopes, oauth, webhook',
                'order' => 10,
            ],
            [
                'question' => self::REMOVED_DROPBOX_QUESTIONS[1],
                'answer' => 'From the app\'s Settings tab (dropbox.com/developers/apps), under "OAuth 2", note the App key and App secret. You\'ll need both to configure Projector\'s .env (see the next question).',
                'keywords' => 'dropbox, credentials, app key, app secret, oauth 2, settings',
                'order' => 20,
            ],
            [
                'question' => self::REMOVED_DROPBOX_QUESTIONS[2],
                'answer' => <<<'TEXT'
                    Add the following to .env, using the credentials from the previous question:

                    DROPBOX_CLIENT_ID=your-app-key
                    DROPBOX_CLIENT_SECRET=your-app-secret

                    DROPBOX_CLIENT_SECRET does double duty: it's the OAuth client secret used to exchange an authorization code for tokens, and the signing key Dropbox uses to sign every webhook notification — there's no separate signing-secret variable the way Slack has one.

                    Testing locally: http://projector.test (or https://projector.test) cannot be used as Dropbox's redirect URI or webhook URI — both must be a real, publicly reachable HTTPS address, the same restriction Slack and Google have. Use Herd's Share feature:

                    1. Herd menu bar app -> projector site -> Share, to get a temporary public HTTPS URL.
                    2. Update the app's OAuth 2 > Redirect URIs and Webhooks > URI (dropbox.com/developers/apps) to that URL's /organizations/dropbox/callback and /dropbox/events paths.
                    3. Restart the site's process from the Herd app — Octane keeps config in memory, so an .env edit alone doesn't take effect until restart.
                    4. Test via the Herd Share URL, not projector.test.

                    Share URLs change each new session, so step 2 needs repeating for further local testing.
                    TEXT,
                'keywords' => 'dropbox, env, environment variables, .env, local development, herd share, testing locally, octane',
                'order' => 30,
            ],
        ];
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Mirrors 2026_09_08_200104_seed_slack_faq_entries.php's structure, including the
     * app-creation/deploy-config steps this time (see
     * 2026_09_09_164248_seed_slack_faq_entries_for_app_creation_and_setup.php, which added the
     * same for Slack after the original Slack seed had deliberately left them out — full
     * instructions belong in the FAQ, not just docs/dropbox-app-setup.md). Editable afterward
     * from the /faq admin UI like any other entry, so this is a starting point, not the source
     * of truth going forward.
     */
    public function up(): void
    {
        $now = now();
        $rows = [];

        foreach ($this->faqs() as $index => $faq) {
            $rows[] = [
                ...$faq,
                'category' => 'Dropbox',
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
        DB::table('faqs')->where('category', 'Dropbox')->delete();
    }

    /**
     * @return list<array{question: string, answer: string, keywords: string}>
     */
    private function faqs(): array
    {
        return [
            [
                'question' => 'How do I create the Dropbox app for Projector, and what permissions does it need?',
                'answer' => $this->createAppAnswer(),
                'keywords' => 'dropbox, app, create, setup, permissions, scopes, oauth, webhook',
            ],
            [
                'question' => 'Where do I find the Dropbox app credentials Projector needs?',
                'answer' => "From the app's Settings tab (dropbox.com/developers/apps), under \"OAuth 2\", note the App key and App secret. You'll need both to configure Projector's .env (see the next question).",
                'keywords' => 'dropbox, credentials, app key, app secret, oauth 2, settings',
            ],
            [
                'question' => 'What environment variables does Projector need for Dropbox, and how do I test the connection locally?',
                'answer' => $this->configureAnswer(),
                'keywords' => 'dropbox, env, environment variables, .env, local development, herd share, testing locally, octane',
            ],
            [
                'question' => "How do I connect my organization's Dropbox account to Projector?",
                'answer' => 'Go to that organization\'s settings page (as an org-admin), Configuration tab, and click "Connect Dropbox". Approve on Dropbox\'s consent screen, and the panel will show the connected account\'s name, its bound folders, and a form to bind more.',
                'keywords' => 'dropbox, connect, account, install, setup',
            ],
            [
                'question' => 'How do I bind a Dropbox folder to a project?',
                'answer' => 'Once Dropbox is connected, type the folder\'s path (e.g. "/Client Intake") into the "Add A Folder" form on the organization\'s Configuration tab and pick a project — there\'s no folder picker to choose from the way there is for Slack channels, since Dropbox has no equivalent "list every folder" call; Projector resolves the typed path to Dropbox\'s own folder id itself, and tells you if that path doesn\'t exist in the connected account.',
                'keywords' => 'dropbox, bind, folder, project, path',
            ],
            [
                'question' => 'How do I import files by dropping them in a bound Dropbox folder?',
                'answer' => 'Drop a CSV, TXT, XLSX, XLS, or DOCX file straight into a bound folder (or a subfolder of it) and Projector imports it — behaves exactly like Slack\'s file import: a spreadsheet gets AI-classified and auto-imports once its column mapping has been confirmed for the project before, otherwise (or for any document) it\'s added to the "Needs Review" queue on the Import Wizard page (under Import in the sidebar) for a human to confirm. See "How do I import a task or event list by uploading a file in Slack?" for the full behavior — it\'s the same pipeline either way.',
                'keywords' => 'dropbox, import, file, upload, csv, spreadsheet, task list, event list, needs review',
            ],
            [
                'question' => 'How do I force what type a document gets imported as from Dropbox?',
                'answer' => 'Drop the file into a subfolder named after the document type\'s short code (e.g. "/Client Intake/meeting-notes/standup.docx" files as Meeting Notes) — no "#" needed, since choosing a folder to drop a file into is already a deliberate act. Only the immediate subfolder counts; a file nested two levels deep isn\'t tagged this way. This is the Dropbox equivalent of Slack\'s #tag override (see "How do I force what type a document gets imported as in Slack?") — Dropbox files have no accompanying message to put a #tag in, so the subfolder name stands in for it.',
                'keywords' => 'dropbox, import, file, upload, subfolder, document type, force, meeting notes, transcription',
            ],
            [
                'question' => 'Who gets notified about a Dropbox import, and why not the person who added the file?',
                'answer' => "Unlike Slack (which tells Projector exactly who posted a file), Dropbox doesn't reliably say who added a given file to a shared account — so every import through a bound folder is attributed to whoever connected the organization's Dropbox account, and that's who gets the result. If they have a Slack account linked (Settings > Integrations) for this organization, they get a Slack DM; otherwise they get an email.",
                'keywords' => 'dropbox, notification, attribution, uploader, email, slack dm',
            ],
            [
                'question' => 'Can I connect the same Dropbox account to more than one Projector organization?',
                'answer' => 'Yes — same as Slack. Each organization gets its own connection and its own folder bindings; which organization a Dropbox file belongs to is always resolved from the specific bound folder it landed in, never assumed from the account alone.',
                'keywords' => 'dropbox, multiple organizations, shared account, agency',
            ],
        ];
    }

    private function createAppAnswer(): string
    {
        return <<<'ANSWER'
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
            ANSWER;
    }

    private function configureAnswer(): string
    {
        return <<<'ANSWER'
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
            ANSWER;
    }
};

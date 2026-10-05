<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CATEGORY = 'Projects';

    /**
     * Same structure as 2026_10_05_193315_seed_user_management_and_account_faq_entries.php —
     * orders start at 1 so this category is listed ahead of Slack/Dropbox on the /faq page,
     * right after User Management and Your Account.
     */
    public function up(): void
    {
        $now = now();
        $rows = [];

        foreach ($this->faqs() as $index => $faq) {
            $rows[] = [
                ...$faq,
                'category' => self::CATEGORY,
                'order' => $index + 1,
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
        DB::table('faqs')->where('category', self::CATEGORY)->delete();
    }

    /**
     * @return list<array{question: string, answer: string, keywords: string}>
     */
    private function faqs(): array
    {
        return [
            [
                'question' => 'Who can create and manage projects?',
                'answer' => 'Org Admins can create, edit, deactivate, and delete projects, and add or edit clients. Project Leads can see every project in the organization and manage a project\'s tags. Team Members work on tasks and documents inside the projects they have access to. See "What can each role do?" under User Management for the full breakdown.',
                'keywords' => 'project, permissions, role, org admin, project lead, team member, create',
            ],
            [
                'question' => 'How do I create a new project?',
                'answer' => 'Go to Projects in the sidebar and click "New Project". Choose the client the project is for (or pick "+ Create New Client" to add one on the spot), enter a Project Name, and fill in "Description / Scope". You can also add a project logo. Then click "Save".

The description is worth a sentence or two: Projector uses it as context whenever AI works on the project\'s documents, so say what the project is, who it\'s for, and what it\'s meant to achieve. When you save, the description is checked automatically — see the next question.',
                'keywords' => 'new project, create project, add project, client, description, scope, logo',
            ],
            [
                'question' => 'What do "AI-Enhanced" and "Description too vague for AI context" mean?',
                'answer' => 'When you save a project, Projector checks whether its description gives the AI enough context to tailor its work to the project. If it does, the project shows an "AI-Enhanced" badge on the Projects page. If it doesn\'t (something like "New website" or "Internal tool"), the form shows "Description needs more detail" with a few suggestions for what to add. You can click "Edit Description" to improve it, or "Save Anyway" to keep it as is — the project then shows "Description too vague for AI context" until the description is updated.',
                'keywords' => 'ai-enhanced, vague, description, badge, suggestions, save anyway',
            ],
            [
                'question' => 'How do I add or edit a client?',
                'answer' => 'Every project belongs to a client. Org Admins can add one while creating a project ("+ Create New Client"), or from the Clients tab on the organization page with "Add Client". A client needs a Company Name (unique within your organization), a Contact Name, and either a Contact Phone or an Email; Industry is optional. Each client row on the Clients tab also has buttons to add a project for that client, edit the client, or delete it.

Deleting a client permanently deletes all of its projects and everything in them. To set a client aside without losing anything, edit it and check "Inactive" instead — its projects are then hidden from the Projects page.',
                'keywords' => 'client, add client, new client, edit client, delete client, company, contact, inactive client',
            ],
            [
                'question' => 'How do I find a project?',
                'answer' => 'The Projects page lists every project you can see, grouped by client — click a client\'s heading to collapse or expand it, or use the search box to filter by project or client name. Clicking a project opens it on its Tasks tab. Once you\'re inside a project, you can jump to another one with the project picker at the top of the page. For projects you use often, add them to your Favorites (see the next question).',
                'keywords' => 'find project, search, switch project, projects page, project picker',
            ],
            [
                'question' => 'How do I add a project to my Favorites?',
                'answer' => 'Click the star next to the project — either beside its name on the Projects page, or next to the project\'s name at the top of the project\'s own page. The star fills in and the project appears under Favorites at the bottom of the sidebar, so you can open it in one click from anywhere in Projector. To remove it, click the star again.

Favorites are personal — only you see your list, and adding a project doesn\'t change anything for anyone else. Your list includes favorites from every organization you belong to, sorted by name, and stays put when you switch organizations. If you haven\'t picked any yet, the sidebar shows "No Favorites Chosen".',
                'keywords' => 'favorites, favorite, star, sidebar, pin, bookmark, quick access',
            ],
            [
                'question' => 'What\'s on a project\'s page?',
                'answer' => 'A project has five tabs:
- Tasks — the project\'s tasks.
- Campaign Calendar — the project\'s events on a calendar, which you can download as a PDF, CSV, or Excel file.
- Documentation — the project\'s documents.
- Transcripts — meeting recordings and their transcripts.
- Reports — task reports you can filter and download.

The main button at the top right changes with the tab you\'re on ("New Task", "New Event", or "New Document").',
                'keywords' => 'project page, tabs, tasks, campaign calendar, documentation, transcripts, reports',
            ],
            [
                'question' => 'How do I rename a project or change its description or logo?',
                'answer' => 'On the Projects page, click the pencil icon next to the project ("Edit Project"). Update the Project Name, "Description / Scope", or logo, then click "Save Changes". Logos can be JPG, PNG, WebP, or GIF files up to 5 MB. Only Org Admins can save changes to a project.',
                'keywords' => 'edit project, rename, change name, description, logo, project settings',
            ],
            [
                'question' => 'How do I add or change a project\'s tags?',
                'answer' => 'Open the project\'s "Edit Project" window (the pencil icon on the Projects page) and use the + button next to Tags. Give the tag a name, pick a color (colors already used by another tag are unavailable), and click "Add". Click an existing tag to rename it, recolor it, or delete it. Tags can then be applied to the project\'s tasks and events, and used to filter reports and calendars. Org Admins and Project Leads can manage tags.',
                'keywords' => 'tags, add tag, tag color, rename tag, delete tag, categories',
            ],
            [
                'question' => 'How do I deactivate a finished project, or bring one back?',
                'answer' => 'To deactivate a project, open "Edit Project", check "Inactive", and click "Save Changes". The project stays on the Projects page with an "Inactive" badge and nothing is deleted, but its tasks and documents become read-only and you can\'t add new ones. To bring it back, open the project and click "Reactivate Project" at the top right. If the project\'s client was also inactive, reactivating the project reactivates the client too.',
                'keywords' => 'inactive, deactivate, archive, reactivate, finished project, close project',
            ],
            [
                'question' => 'How do I delete a project?',
                'answer' => 'On the Projects page, click the trash icon next to the project ("Delete Project"), and confirm. This permanently deletes the project along with all of its tasks, events, documents, and transcripts, and removes any Slack channel or Dropbox folder linked to it. It can\'t be undone, so if you might need the project again, deactivate it instead (see the previous question). Only Org Admins can delete projects.',
                'keywords' => 'delete project, remove project, permanent, trash',
            ],
        ];
    }
};

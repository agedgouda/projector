<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * New categories, listed in this order after Reports (orders start at 1, same as every other
     * end-user category — the /faq page groups by first appearance in (order, id) order).
     *
     * @var list<string>
     */
    private const NEW_CATEGORIES = [
        'Tasks & Events',
        'Documents',
        'Transformations',
        'Status Meetings',
        'Dashboard & Help',
        'Organization Settings',
    ];

    private const TRANSCRIPT_WAYS_QUESTION = 'What are the ways to get a meeting transcript into a project?';

    private const TRANSCRIPT_WAYS_ANCHOR = '- Paste it in — on the Transcripts tab, click "New Document", paste the transcript, and click "Create Transcription".';

    private const TRANSCRIPT_WAYS_PHONE_LINE = '- Record on your phone — in the Projector mobile app, tap "Record a Meeting", pick the project, and tap to start recording. It uploads and is transcribed when you stop.';

    public function up(): void
    {
        $now = now();
        $rows = [];

        foreach ($this->newCategoryFaqs() as $category => $faqs) {
            foreach ($faqs as $index => $faq) {
                $rows[] = [...$faq, 'category' => $category, 'order' => $index + 1, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        foreach ($this->additionsToExistingCategories() as $faq) {
            $rows[] = [...$faq, 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table('faqs')->insert($rows);

        // Same "only if still un-customized" spirit as the earlier FAQ updates: only touches
        // the transcript list if it still has the line this bullet is meant to follow.
        $ways = DB::table('faqs')->where('question', self::TRANSCRIPT_WAYS_QUESTION)->first();

        if ($ways !== null && str_contains($ways->answer, self::TRANSCRIPT_WAYS_ANCHOR) && ! str_contains($ways->answer, self::TRANSCRIPT_WAYS_PHONE_LINE)) {
            DB::table('faqs')->where('id', $ways->id)->update([
                'answer' => str_replace(self::TRANSCRIPT_WAYS_ANCHOR, self::TRANSCRIPT_WAYS_ANCHOR."\n".self::TRANSCRIPT_WAYS_PHONE_LINE, $ways->answer),
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('faqs')->whereIn('category', self::NEW_CATEGORIES)->delete();
        DB::table('faqs')->whereIn('question', array_column($this->additionsToExistingCategories(), 'question'))->delete();

        $ways = DB::table('faqs')->where('question', self::TRANSCRIPT_WAYS_QUESTION)->first();

        if ($ways !== null) {
            DB::table('faqs')->where('id', $ways->id)->update([
                'answer' => str_replace("\n".self::TRANSCRIPT_WAYS_PHONE_LINE, '', $ways->answer),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return list<array{category: string, question: string, answer: string, keywords: string, order: int}>
     */
    private function additionsToExistingCategories(): array
    {
        return [
            [
                'category' => 'Your Account',
                'order' => 8,
                'question' => 'How do I connect my Google account?',
                'answer' => 'Go to Settings > Integrations and click "Connect Google Account", then sign in and allow access when Google asks. This lets Projector import your Google Docs and create Google Sheets and Google Docs in your Drive when you export a report or document. You\'ll also be prompted to connect automatically the first time you use one of those features. You can disconnect it from the same page.',
                'keywords' => 'google, google account, connect, integrations, google docs, google sheets, drive',
            ],
            [
                'category' => 'Your Account',
                'order' => 9,
                'question' => 'How do I set up a new organization of my own?',
                'answer' => 'If you sign up without an invitation (the "Sign up" link on the login page), Projector asks you to create your organization: enter its name, choose a plan, and — for Pro — how many people you\'ll be inviting. You become the organization\'s Org Admin and can start adding clients, projects, and people. If you were invited to an existing organization, use the link in your invitation email instead (see "I received an invitation email. How do I join?").',
                'keywords' => 'new organization, create organization, sign up, register, plan, org admin',
            ],
            [
                'category' => 'Transcripts & Meeting Notes',
                'order' => 5,
                'question' => 'Can I record a meeting on my phone?',
                'answer' => 'Yes, with the Projector mobile app. Tap "Record a Meeting", choose the project, and tap to start recording (allow microphone access the first time). Tap again to stop. The recording uploads, is transcribed, and becomes Meeting Notes automatically, just like any other transcript — the app opens the result when it\'s ready.',
                'keywords' => 'phone, mobile app, record, recording, meeting, microphone, iphone, android',
            ],
        ];
    }

    /**
     * @return array<string, list<array{question: string, answer: string, keywords: string}>>
     */
    private function newCategoryFaqs(): array
    {
        return [
            'Tasks & Events' => [
                [
                    'question' => 'How do I create a task?',
                    'answer' => 'On a project\'s Tasks tab, click "New Task". Give it a name, and fill in any of the rest: a description, Assignee, Due Date, Priority, Status, and Tags. Tasks can also come from Slack ("/task"), from importing a task list, or from a transformation that turns Meeting Notes into tasks. Anyone working in the project can create tasks.',
                    'keywords' => 'new task, create task, add task, assignee, due date, priority, status',
                ],
                [
                    'question' => 'How do I update or delete a task?',
                    'answer' => 'Click a task to open it, then change whatever you need — name, description, assignee, due date, priority, status, or tags. To change just the status, you can also drag the task to another column on the board. To delete a task, open it and use its delete button; this can\'t be undone. If the project has sub-projects, the task\'s "Board" field moves it to another sub-project with the same columns.',
                    'keywords' => 'edit task, update task, delete task, change status, drag, move task, board',
                ],
                [
                    'question' => 'How does the task board work?',
                    'answer' => 'The Tasks tab shows the project\'s tasks as a board, with one column per status. Drag a task between columns to change its status. Above the board you can search tasks or people, filter by priority or tag (including "None" for untagged tasks), and sort by Due Date, Priority, or Created Date.',
                    'keywords' => 'kanban, board, columns, drag, search, filter, sort, priority, tag',
                ],
                [
                    'question' => 'How do I add, rename, or remove a board column?',
                    'answer' => 'Each project starts with four columns: To Do, In Progress, In Review, and Done. Org Admins can add a column with the + button next to the column headings (it\'s added at the end), and rename or delete a column from its menu. A column that still has tasks in it can\'t be deleted — move or clear its tasks first. Columns belong to each project, so changing one project\'s columns doesn\'t affect another\'s.',
                    'keywords' => 'kanban column, add column, rename column, delete column, status, workflow',
                ],
                [
                    'question' => 'What are internal and external due dates?',
                    'answer' => 'Some teams need two deadlines for the same task — for example, the date the team is aiming for and a later date shared with the client. An Org Admin can turn this on with "Track separate internal and external due dates on tasks" on the organization\'s Configuration tab. Once it\'s on, every task has both an Internal and an External due date.',
                    'keywords' => 'internal due date, external due date, deadline, client date, due dates',
                ],
                [
                    'question' => 'How do I tag a task or event?',
                    'answer' => 'Click the + ("Add a tag") on a task card, or use the Tags section when the task is open, and pick from the project\'s tags. Remove a tag by clicking its ×. An event can have only one tag. The list of tags itself is managed in the project\'s "Edit Project" window (see "How do I add or change a project\'s tags?").',
                    'keywords' => 'tag, tags, add tag, remove tag, label, category',
                ],
                [
                    'question' => 'How do comments and @mentions work?',
                    'answer' => 'Open a task or document and use its Discussion section to leave a comment — press Cmd + Enter (Ctrl + Enter on Windows) to post. You can sort comments oldest or newest first, and edit or delete your own.

Typing @ followed by a name in a document mentions that person. When a document is turned into tasks by a transformation, tasks for a mentioned person are assigned to them automatically. Mentions don\'t send anyone a notification.',
                    'keywords' => 'comments, discussion, mention, @mention, reply, notify',
                ],
                [
                    'question' => 'How do I create an event?',
                    'answer' => 'On a project\'s Campaign Calendar tab, click "New Event". Give it a name and description, set its Start Date and End Date, and optionally pick one tag, then save. Events can also come from Slack ("/events") or from importing an event list.',
                    'keywords' => 'new event, create event, calendar, start date, end date',
                ],
                [
                    'question' => 'How do I use the Campaign Calendar?',
                    'answer' => 'The Campaign Calendar shows a project\'s tasks and events by month. Use the arrows to change months and "Today" to jump back. You can show tasks, events, or both, filter by tag (including "None" for untagged items), and hide individual sub-projects if the project has any. Click an item to see its details. The PDF, CSV, and Excel buttons download what you\'re showing (see "How do I download a project\'s calendar?").',
                    'keywords' => 'campaign calendar, calendar, month, filter, tag, sub-project, events, tasks',
                ],
            ],
            'Documents' => [
                [
                    'question' => 'What\'s on the Documentation tab?',
                    'answer' => 'A project\'s Documentation tab lists every document in the project, grouped into folders by document type (for example, Meeting Notes), with a count on each folder. Use "Search documentation..." to find one by name, and click a document to open it. A dot marks newly imported documents you haven\'t opened yet.',
                    'keywords' => 'documentation tab, documents, folders, search, document list',
                ],
                [
                    'question' => 'What are document types?',
                    'answer' => 'Every document has a type, which decides which folder it\'s in and what can happen to it next. The most common are Transcription, Meeting Notes, Task, and Event. Others appear as you use them — a transformation creates documents of its own type, and importing a file as "Other" adds a new type with the name you give it.',
                    'keywords' => 'document type, types, transcription, meeting notes, task, event, folder',
                ],
                [
                    'question' => 'How do I create a document?',
                    'answer' => 'Click "New Document" on the project\'s Documentation tab (it starts as Meeting Notes) or on the Transcripts tab (it starts as a Transcription). Give it a name, write or paste the content, add tags or success criteria if you like, and click "Create". You can also add "AI Processing Instructions" (see "What does the \"Additional Information\" box do when I import a transcript?" — it works the same way).',
                    'keywords' => 'new document, create document, write, meeting notes, transcription',
                ],
                [
                    'question' => 'How do I edit or delete a document?',
                    'answer' => 'Open the document and click "Edit", make your changes, and save. To delete it, use the delete button at the top of the document and confirm. Deleting a document also deletes everything that was generated from it — for example, deleting a transcript deletes its Meeting Notes — and can\'t be undone.',
                    'keywords' => 'edit document, delete document, change, remove',
                ],
                [
                    'question' => 'Can I add images or files to a document?',
                    'answer' => 'Yes. Use the paperclip button in the editor\'s toolbar, or paste or drag an image straight into the content. Each file can be up to 2 MB; program and web-page files (such as .exe or .html) aren\'t allowed. Images in a document are carried through to anything the AI generates from it.',
                    'keywords' => 'attach, attachment, image, file, upload, paperclip, picture',
                ],
            ],
            'Transformations' => [
                [
                    'question' => 'What is a transformation?',
                    'answer' => 'A transformation is an AI step that turns one document into another — for example, turning Meeting Notes into a set of tasks, or into a proposal. The only one that runs on its own is Transcript to Meeting Notes (see "How does a transcript become Meeting Notes?"); every other transformation runs only when someone chooses it.',
                    'keywords' => 'transformation, ai, transform, convert, workflow, meeting notes to tasks',
                ],
                [
                    'question' => 'How do I run a transformation on a document?',
                    'answer' => 'Open the document (Meeting Notes, for example) and click "Transform" in its sidebar. Pick a transformation from the list and click "Run". The results are created as new documents — tasks appear on the project\'s board, other types in their own folder on the Documentation tab. If the document already has results from an earlier run, you\'ll be asked to confirm, since running again replaces them.

"Transform" isn\'t offered on tasks, on transcripts, or on a document that\'s still being processed.',
                    'keywords' => 'run transformation, transform, run, generate tasks, ai, convert document',
                ],
                [
                    'question' => 'What do the "Process" and "Reprocess" buttons do?',
                    'answer' => 'Some documents have a defined next step — a transcript\'s is Meeting Notes, and a document created by a multi-step workflow has the workflow\'s next step. "Process" runs that step for the first time; "Reprocess" runs it again and overwrites the previous result, which can\'t be undone. When reprocessing, you can add "Instructions for this run only".',
                    'keywords' => 'process, reprocess, rerun, next step, workflow, overwrite',
                ],
                [
                    'question' => 'How do I create my own transformation?',
                    'answer' => 'Org Admins manage transformations in the Transformation Library — choose "Transformations" from the menu under your name. It lists Global Templates, which everyone can use, and your organization\'s own under My Organization. To make one, click "New Transformation" and fill in:
- Name and Description.
- Output Type Key — the kind of document it creates, e.g. task.
- Create Single Document (one cohesive document, like a proposal) or Create a Document Set (a list of separate items, like tasks).
- System Instructions and User Prompt — what the AI should do. Use {{input}} in the prompt where the source document goes.

Not sure how to write the instructions? Click "Generate with AI", describe what the transformation should do, and Projector drafts them for you. To customize a global template, duplicate it — the copy belongs to your organization and can be edited.',
                    'keywords' => 'transformation library, new transformation, template, create transformation, prompt, duplicate',
                ],
                [
                    'question' => 'Can I reuse the setup from a Smart Import?',
                    'answer' => 'Yes. When you finish setting up a Smart Import, click "Save as Transformation…" and give it a name. The next time anyone in your organization uses Smart Import, they can pick it instead of "Start fresh (AI-detect)", and the file is set up the same way. See "What is Smart Import (\"Import Data\")?" under Importing.',
                    'keywords' => 'save as transformation, smart import, import data, reuse, saved import',
                ],
            ],
            'Status Meetings' => [
                [
                    'question' => 'What are Status Meetings?',
                    'answer' => 'Status Meetings (in the sidebar) are for meetings that cover several projects at once, like a weekly status review. You add the meeting\'s notes or recording once, and Projector drafts separate notes for each project it covers, for you to review and file. Status Meetings are managed by Org Admins. To add one, see "How do I import notes from a meeting that covered several projects?" under Importing.',
                    'keywords' => 'status meeting, status meetings, multiple projects, weekly meeting, overview',
                ],
                [
                    'question' => 'How do I review and file a Status Meeting\'s drafts?',
                    'answer' => 'When Projector finishes reading a Status Meeting, it shows a "Review" button. Each draft covers one project: check or edit its title and content, and if no project was matched, assign it to one (or create a new project). Then click "Commit to Project" to file it into that project. Click "Process" to run a Status Meeting that hasn\'t been processed yet, or "Reprocess" to draft it again.',
                    'keywords' => 'review, draft, commit to project, assign, process, reprocess, status meeting',
                ],
                [
                    'question' => 'How do I delete a Status Meeting?',
                    'answer' => 'Open the Status Meeting and use its delete button, then confirm. Notes already committed to projects stay in those projects.',
                    'keywords' => 'delete status meeting, remove',
                ],
            ],
            'Dashboard & Help' => [
                [
                    'question' => 'What does the Dashboard show?',
                    'answer' => 'The Dashboard shows the tasks across all of your organization\'s projects on one board, with a row for each project that has tasks. Org Admins and Project Leads see every task; Team Members see the tasks assigned to them. The same search, priority and tag filters, and sorting as a project\'s board are available, and you can drag tasks between columns to update them.',
                    'keywords' => 'dashboard, home, overview, tasks, all projects, board',
                ],
                [
                    'question' => 'How do I report a bug?',
                    'answer' => 'Click "Report a Bug" at the bottom of the sidebar. Give it a short title, describe what you did, what you expected, and what actually happened, and paste the address of the page where it went wrong into the page field. Then click "Submit Bug Report".',
                    'keywords' => 'bug, report a bug, problem, issue, error, support, feedback',
                ],
            ],
            'Organization Settings' => [
                [
                    'question' => 'How do I add my organization\'s logo and PDF branding?',
                    'answer' => 'Org Admins can add the organization\'s logo from the organization page: hover over the logo square at the top, click the pencil, and choose an image (or click the × to remove it). The logo appears in the organization picker. For downloaded PDFs, open the Configuration tab, expand "PDF Branding", and upload a "PDF Header Image" and "PDF Footer Image" — these appear on task report, calendar, and document PDFs.',
                    'keywords' => 'logo, branding, pdf header, pdf footer, white label, organization logo',
                ],
                [
                    'question' => 'How do I set up my organization\'s meeting provider?',
                    'answer' => 'An Org Admin opens the organization\'s Configuration tab, expands "Meeting Provider", and chooses Zoom, Microsoft Teams, Google Meet, or Slack. Each provider needs a few connection details from that provider\'s own admin settings — click "Setup Guide" for step-by-step instructions — then click "Save". Once it\'s set, recordings appear on each project\'s Transcripts tab (see "How do I import a meeting recording?").',
                    'keywords' => 'meeting provider, zoom, teams, google meet, slack, recordings, setup guide, configuration',
                ],
                [
                    'question' => 'What are the LLM Driver and Embeddings Driver settings?',
                    'answer' => 'They choose which AI service your organization uses — the LLM Driver for writing (Meeting Notes, transformations, reports from Slack) and the Embeddings Driver for search. Both default to "System Default", which works without any setup. An Org Admin can switch to another provider (such as OpenAI, Google Gemini, or Anthropic Claude) on the Configuration tab by entering that provider\'s API key and model.',
                    'keywords' => 'llm driver, embeddings driver, ai provider, openai, gemini, claude, api key, model',
                ],
                [
                    'question' => 'What does the AI Usage tab show?',
                    'answer' => 'The AI Usage tab on the organization page shows how many documents the AI has processed for your organization, broken down by client and project, with a total at the bottom.',
                    'keywords' => 'ai usage, usage, documents processed, ai documents, limit',
                ],
                [
                    'question' => 'What are the plan limits, and what happens when we reach one?',
                    'answer' => 'The Free plan includes 1 user, 1 client, 1 project, and 10 AI-processed documents a month. The Pro plan has unlimited users, clients, and projects, and 100 AI-processed documents a month. When your organization reaches a limit, Projector shows an "Upgrade to Pro" message instead of adding more — contact your administrator to upgrade. On Pro, adding people beyond the number you planned for shows an "Additional User Charge" notice to confirm before continuing.',
                    'keywords' => 'plan, limits, free, pro, upgrade, users, projects, clients, ai documents, billing',
                ],
            ],
        ];
    }
};

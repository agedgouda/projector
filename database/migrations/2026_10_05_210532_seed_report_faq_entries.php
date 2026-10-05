<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CATEGORY = 'Reports';

    /**
     * The Slack /report entry (seeded by 2026_10_05_192533_update_slack_and_dropbox_faqs_for_end_users.php)
     * moves here so every way to run a report lives under one category.
     */
    private const SLACK_REPORT_QUESTION = 'How do I get a task report or event calendar from Slack?';

    /**
     * Same structure as 2026_10_05_203534_seed_transcript_faq_entries.php, listed right after
     * Transcripts & Meeting Notes and ahead of Slack/Dropbox.
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

        DB::table('faqs')
            ->where('category', 'Slack')
            ->where('question', self::SLACK_REPORT_QUESTION)
            ->update(['category' => self::CATEGORY, 'order' => count($rows) + 1, 'updated_at' => $now]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('faqs')
            ->where('category', self::CATEGORY)
            ->where('question', self::SLACK_REPORT_QUESTION)
            ->update(['category' => 'Slack', 'order' => 55, 'updated_at' => now()]);

        DB::table('faqs')->where('category', self::CATEGORY)->delete();
    }

    /**
     * @return list<array{question: string, answer: string, keywords: string}>
     */
    private function faqs(): array
    {
        return [
            [
                'question' => 'What reports can I get from Projector?',
                'answer' => 'There are four kinds, all available to anyone who can see the project:
- Task reports — on a project\'s Reports tab, filter the project\'s tasks and download the result as Excel, Word, PDF, Google Sheets, or Google Docs.
- Calendars — on a project\'s Campaign Calendar tab, download its tasks and events as a PDF calendar, CSV, or Excel file.
- Single documents — download any document, such as a set of Meeting Notes, as a PDF or Word file, or send it to Google Docs.
- From Slack — run "/report" in a channel bound to a project to get a task report or calendar posted right there.

Each one is explained in the questions below.',
                'keywords' => 'reports, report, download, export, overview, excel, pdf, word, google sheets, google docs, slack',
            ],
            [
                'question' => 'How do I run a task report on the website?',
                'answer' => 'Open the project and go to its Reports tab. Choose your filters:
- Project — if the project has sub-projects, pick which ones to include.
- Assignee — one or more people (or leave it as "Anyone").
- Status — one or more statuses (or leave it as "Any Status").
- Due or Done, with a From and To date — "Due" filters by when tasks are due; "Done" filters by when they were completed.

Click "Search" to see the matching tasks. Click a column heading to sort by it, and click a task to open it. You can also change a task\'s status, assignee, due date, or tags right in the results. Projector remembers your last filters for each project, even on another computer; "Reset" clears them.',
                'keywords' => 'task report, reports tab, filter, assignee, status, due, done, date range, search, sort',
            ],
            [
                'question' => 'How do I download a task report?',
                'answer' => 'After you run a search on the Reports tab, use the buttons above the results:
- Excel, Word, or PDF — downloads the file to your computer.
- Google Sheets or Google Docs — creates the file in your Google Drive and opens it in a new tab. The first time, you\'ll be asked to connect your Google account.

Every download contains exactly the tasks you searched for, in the order you sorted them. Check "Include task details column in export" first if you want each task\'s full description included too.',
                'keywords' => 'download report, export, excel, word, pdf, google sheets, google docs, task details',
            ],
            [
                'question' => 'How do I download a project\'s calendar?',
                'answer' => 'On the project\'s Campaign Calendar tab, click "PDF", "CSV", or "Excel":
- PDF — a printable calendar, one month after another.
- CSV or Excel — a simple list with Date, Title, and Tags columns.

The download covers the whole calendar from the current month onward, not just the month on screen. It follows what you\'re showing on the calendar — tasks, events, or both, any tag filter, and any sub-projects you\'ve hidden. Items with no date aren\'t included. To get a different period (e.g. a past month), use "/report" in Slack (see "How do I get a task report or event calendar from Slack?").',
                'keywords' => 'calendar, campaign calendar, download, export, pdf, csv, excel, events, tasks',
            ],
            [
                'question' => 'How do I download a single document?',
                'answer' => 'Open the document and use the buttons in its sidebar: "Export As PDF" or "Export As Word" to download it, or "Export To Google Docs" to create a copy in your Google Drive (you\'ll be asked to connect your Google account the first time).',
                'keywords' => 'download document, export document, pdf, word, google docs, meeting notes',
            ],
        ];
    }
};

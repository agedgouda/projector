<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CATEGORY = 'Importing';

    /**
     * One place in the FAQ for every import path in the app (Import page, project tabs, Status
     * Meetings, recordings, Slack, Dropbox) — the Slack and Dropbox sections keep their own
     * detailed entries, which the overview here points to. Same structure as
     * 2026_10_05_200824_seed_project_faq_entries.php; orders start at 1 so this category is
     * listed right after Projects and ahead of Slack/Dropbox on the /faq page.
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
                'question' => 'What are all the ways to import into Projector?',
                'answer' => 'There are several, depending on what you\'re bringing in and where you are:
- The Import page (Import in the sidebar) — one place to import anything into any project: pick a project, then choose Document, Task List, Event List, or Smart Import.
- Buttons on a project\'s own tabs — "Import Tasks" and "Import Data" on the Tasks tab, "Import Events" and "Import Data" on the Campaign Calendar tab, and "Import Document" on the Documentation tab. These do exactly the same thing as the Import page, just with the project already chosen.
- Meeting recordings — on a project\'s Transcripts tab, import a recording from your organization\'s meeting provider, or capture a meeting live from a browser tab.
- Status Meetings (in the sidebar) — for notes or a recording from a meeting that covered several projects at once; Projector splits it up by project for you.
- Slack — drop a file into a channel that\'s bound to a project (see "How do I import a task or event list by uploading a file in Slack?").
- Dropbox — drop a file into a folder that\'s bound to a project (see "How do I import files by dropping them in a bound Dropbox folder?").

Importing into a project takes an Org Admin or Project Lead, and the project must be active. Each method is explained in the questions below.',
                'keywords' => 'import, upload, ways to import, import page, overview, file, bring in',
            ],
            [
                'question' => 'How do I import a document such as a Google Doc, Word file, or text file?',
                'answer' => 'On the Import page choose your project and then "Document", or click "Import Document" on the project\'s Documentation tab. In the "Import a Document" window, first choose what to import it as:
- Transcription (the default) — for raw notes or a meeting transcript. Before importing you can add optional "Additional Information" for the AI, and Projector then generates Meeting Notes from it.
- Any other document type the project already uses — the document is filed as-is under that type, with no AI processing.
- Other — type a name to create a new document type and file it under that.

Then pick the source: "Import from Google Docs" (the first time, you\'ll be asked to connect your Google account) or "Upload Word or Text File (.docx, .txt)", up to 10 MB. If your organization has a meeting provider set up, its recent recordings are listed in the same window too.',
                'keywords' => 'import document, google doc, word, docx, txt, text file, transcription, meeting notes, upload',
            ],
            [
                'question' => 'How do I import a list of tasks or events from a spreadsheet?',
                'answer' => 'On the Import page choose your project and then "Task List" or "Event List" — or click "Import Tasks" on the project\'s Tasks tab, or "Import Events" on its Campaign Calendar tab. Pick a CSV, XLSX, XLS, or TXT file, up to 10 MB. Projector then shows how it matched your spreadsheet\'s columns to task or event fields; change any that are wrong (or leave a column as "Not mapped" to skip it), check "My file doesn\'t have a header row" if your first row is data rather than column names, and use Preview to see what will be created. Then click Import — progress shows at the top of the page while it runs.

If your file mixes tasks and events, or isn\'t a neat spreadsheet, use Smart Import instead (see the next question).',
                'keywords' => 'import tasks, import events, spreadsheet, csv, excel, xlsx, task list, event list, column mapping',
            ],
            [
                'question' => 'What is Smart Import ("Import Data")?',
                'answer' => 'Smart Import lets AI work out what\'s in a file for you. Choose "Smart Import" on the Import page, or click "Import Data" on a project\'s Tasks or Campaign Calendar tab, and pick a spreadsheet (CSV, XLSX, XLS) or a text file (TXT or Markdown). Projector analyzes it and detects the tasks and events it contains — even a mix of both in one file — and walks you through each detected list with Previous and Next so you can check and adjust it before importing.

If you import the same kind of file regularly, use "Save as Transformation…" to save how it was set up. Next time, choose that saved transformation instead of "Start fresh (AI-detect)" and the file is set up the same way.',
                'keywords' => 'smart import, import data, ai, transformation, mixed, tasks and events, detect',
            ],
            [
                'question' => 'How do I import a meeting recording?',
                'answer' => 'First, an Org Admin sets your organization\'s meeting provider (Zoom, Microsoft Teams, Google Meet, or Slack) on the organization\'s Configuration tab. Until then, the Transcripts tab shows "No meeting provider configured".

Once it\'s set up, recent recordings appear on each project\'s Transcripts tab (and in the "Import a Document" window). Click "Import" next to a recording, add any optional "Additional Information" for the AI, and save — Projector transcribes it and generates Meeting Notes. To hide a recording you don\'t want in this project, use its dismiss button so it isn\'t imported by mistake.',
                'keywords' => 'recording, meeting recording, zoom, teams, google meet, slack, transcript, transcription, meeting provider',
            ],
            [
                'question' => 'Can I record a meeting that\'s happening in my browser?',
                'answer' => 'Yes. On a project\'s Transcripts tab, click "Capture Live Meeting Audio" and share the browser tab your call is running in (on Windows you can share your whole screen instead). Click "Stop Capture" when the meeting ends and it\'s transcribed automatically. Audio uploads continuously as you go, so a dropped connection or closed tab only loses the last few seconds. This works in the latest Chrome or Edge. On a Mac it only works for calls running in a browser tab — not desktop apps like Zoom or Slack.',
                'keywords' => 'capture, live meeting, record, browser tab, audio, chrome, edge, transcribe',
            ],
            [
                'question' => 'How do I import notes from a meeting that covered several projects?',
                'answer' => 'Use Status Meetings in the sidebar. Click "New Status Meeting" to give it a title and paste in your notes or transcript, or use its Recordings tab to import a Google Doc, a Word or text file, or a meeting recording instead (you can add AI instructions there, e.g. "Clean this up into full meeting notes"). Projector reads the whole meeting and drafts separate notes for each project it covers. Review each draft — edit the title or content, assign it to a project if none was matched (or create a new project), then click "Commit to Project" to file it.',
                'keywords' => 'status meeting, multiple projects, meeting notes, split, draft, commit to project, review',
            ],
            [
                'question' => 'What is the "Needs Review" list on the Import page?',
                'answer' => 'Files that arrive through Slack or Dropbox are imported automatically when Projector is sure what they are. When it isn\'t — a spreadsheet layout the project hasn\'t seen before, or a Word document that wasn\'t tagged with a type — the file waits under "Needs Review" on the Import page instead. Open it to finish the import in the same Smart Import window, confirming or correcting what the AI proposed; there\'s no need to upload the file again. Once a spreadsheet\'s layout has been confirmed, files with the same layout import automatically from then on. If you don\'t want a file, dismiss it — that can\'t be undone, and whoever uploaded it is notified.',
                'keywords' => 'needs review, pending import, queue, slack, dropbox, dismiss, confirm',
            ],
        ];
    }
};

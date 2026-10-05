<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CATEGORY = 'Transcripts & Meeting Notes';

    /**
     * Every way a transcript (the "intake" document type, labelled Transcription) gets into a
     * project, and the one automatic AI step it triggers — DocumentObserver::created() →
     * ProjectAiService::process() with the "Transcript to Meeting Notes" template. Later
     * transformation steps are deliberately left to their own FAQ entries. Same structure as
     * 2026_10_05_202813_seed_importing_faq_entries.php, listed right after Importing.
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
                'question' => 'What are the ways to get a meeting transcript into a project?',
                'answer' => 'Any of these adds the transcript to the project as a Transcription document, and every one of them leads to Meeting Notes automatically (see the next question):
- Import a recording — on the project\'s Transcripts tab, click "Import" next to a recording from your organization\'s meeting provider (Zoom, Microsoft Teams, Google Meet, or Slack). Projector fetches and transcribes it.
- Capture a live meeting — on the Transcripts tab, click "Capture Live Meeting Audio" and share the browser tab your call is in. It\'s transcribed when you stop.
- Import a file — click "Import Document" on the Documentation tab (or use the Import page), leave "Import As" set to Transcription, and choose a Google Doc or a Word or text file.
- Paste it in — on the Transcripts tab, click "New Document", paste the transcript, and click "Create Transcription".
- Slack — drop a Word document into a bound channel with #transcription in the file name or in the message.
- Dropbox — drop a Word document into a subfolder named "transcription" inside a bound folder.

The import options inside Projector (the first three) take an Org Admin or Project Lead; anyone working in the project can paste one in. For a meeting that covered several projects, use Status Meetings instead — see "How do I import notes from a meeting that covered several projects?" under Importing.',
                'keywords' => 'transcript, transcription, recording, ingest, import, paste, capture, zoom, teams, google meet, slack, dropbox',
            ],
            [
                'question' => 'How does a transcript become Meeting Notes?',
                'answer' => 'Automatically. As soon as a transcript is added to a project, Projector reads it and writes a Meeting Notes document called "Meeting Notes for <transcript name>". It contains:
- A two-to-three sentence summary of the meeting.
- A numbered list of every action item — what needs to be done and, where the transcript says, who owns it — including small logistical tasks. Items the meeting marked as high, medium, or low priority keep that priority.

If the transcript already has a clearly labeled "Action Items" (or "To Dos") section, only the items in that section are used. When you import a transcript, you\'re taken straight to its Meeting Notes, which fill in as soon as the AI finishes — progress shows at the top of the page. The original transcript is kept unchanged.

This is the only step that happens on its own, and only for documents of the Transcription type — a file imported as any other type is filed as-is. Anything beyond Meeting Notes is a step you choose yourself.',
                'keywords' => 'meeting notes, automatic, summary, action items, ai, transcript, transcription, priority',
            ],
            [
                'question' => 'What does the "Additional Information" box do when I import a transcript?',
                'answer' => 'When you import a recording or a Transcription file, Projector asks for optional "Additional Information" before it starts. Leave it blank to get standard Meeting Notes (summary plus action items, as described above). If you type something, choose how it\'s used:
- Add to the standard instructions (the default) — your text is extra guidance and you still get standard Meeting Notes. For example: "The client is Acme — list an owner for every action item."
- Replace the standard instructions — your text becomes the whole instruction, so you can create something the standard Meeting Notes don\'t cover. Write it as a complete instruction, for example: "Write a one-page client recap of the decisions made, with no internal notes." Whatever comes back is still saved as the transcript\'s Meeting Notes.

Your choice is saved with the transcript, so reprocessing it later uses the same instructions. A transcript pasted in with "New Document" has the same option, in its "AI Processing Instructions" box.',
                'keywords' => 'additional information, instructions, custom prompt, meeting notes, import, transcript',
            ],
            [
                'question' => 'Can I regenerate a transcript\'s Meeting Notes?',
                'answer' => 'Yes. Open the transcript and click "Reprocess" (it says "Process" if no Meeting Notes have been made yet). You can add "Instructions for this run only" — for example, "Only extract from the section labeled Action Items this time" — which apply to that one run and aren\'t saved. Reprocessing overwrites the current Meeting Notes, including any edits made to them, and can\'t be undone.',
                'keywords' => 'reprocess, regenerate, redo, meeting notes, rerun, instructions, transcript',
            ],
        ];
    }
};

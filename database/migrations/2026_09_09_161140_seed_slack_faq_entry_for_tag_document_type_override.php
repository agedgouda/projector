<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const QUESTION = 'How do I force what type a document gets imported as in Slack?';

    private const ANSWER = 'Every document type in a project\'s catalog — Task, Event, Meeting Notes, Transcription, or any other type the project uses — has a short code auto-derived from its name: lowercased, with spaces turned into hyphens (e.g. "Meeting Notes" becomes meeting-notes). Include #<that code> either in the file\'s name (e.g. standup-notes-#meeting-notes.docx) or in the message you drop the file with (e.g. "here\'s today\'s call — #transcription"), and a DOCX upload files immediately as that type — no AI classification, no trip through the Needs Review queue. Checking the filename as well as the message means this still works even from somewhere that can only set a filename with no message attached. #task and #event aren\'t forceable this way, since either one still needs an AI-written extraction rule either way — there\'s no step to skip for those two. If two different tags turn up (say, one in the filename and a different one in the message), neither is trusted and the file goes to Needs Review instead of guessing.';

    private const KEYWORDS = 'slack, import, file, upload, tag, hashtag, document type, force, meeting notes, transcription';

    /**
     * A dedicated FAQ entry alongside the existing "How do I import a task or event list by
     * uploading a file in Slack?" (see the migration updating that one's answer for the same
     * feature) — the #tag override is a distinct enough workflow (and answer to a distinct
     * enough question — "how do I force it" vs. "how does it normally work") to warrant its
     * own entry rather than being folded into the existing one. Editable afterward from the
     * /faq admin UI like any other entry.
     */
    public function up(): void
    {
        DB::table('faqs')->insert([
            'category' => 'Slack',
            'question' => self::QUESTION,
            'answer' => self::ANSWER,
            'keywords' => self::KEYWORDS,
            'order' => 75,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('faqs')
            ->where('category', 'Slack')
            ->where('question', self::QUESTION)
            ->where('answer', self::ANSWER)
            ->delete();
    }
};

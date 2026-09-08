<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const QUESTION = 'How do I import a task or event list by uploading a file in Slack?';

    private const OLD_ANSWER = 'Drop a CSV, TXT, XLSX, or XLS file straight into a channel that\'s bound to a project. Projector downloads it and figures out automatically whether it\'s a task list, an event list, or a mix of both. The first time your project sees a given column layout, it\'s added to a "Needs Review" queue on the Import Wizard page (under Import in the sidebar) instead of importing right away, so a human confirms the mapping once — after that, the same column layout imports immediately every time. A file the AI can\'t confidently classify at all lands in the same queue for manual review. Either way, the bot replies in the channel with what happened and a link to review or see the result.';

    private const NEW_ANSWER = 'Drop a CSV, TXT, XLSX, XLS, or DOCX file straight into a channel that\'s bound to a project. A spreadsheet (CSV/TXT/XLSX/XLS): Projector figures out automatically whether it\'s a task list, an event list, or a mix of both — the first time your project sees a given column layout, it\'s added to a "Needs Review" queue on the Import Wizard page (under Import in the sidebar) instead of importing right away, so a human confirms the mapping once; after that, the same column layout imports immediately every time. A Word document (DOCX): since there\'s no column layout to recognize, it always goes to that same "Needs Review" queue for a human to classify by hand. Either way, the bot replies in the channel with what happened and a link to review or see the result.';

    /**
     * Only updates the row if it still has its previous, un-customized text — an admin who's
     * already edited it from the /faq page keeps their own wording. See
     * 2026_09_08_203150_update_slack_faq_file_import_answer.php for the same pattern applied
     * one step earlier for this same row.
     */
    public function up(): void
    {
        DB::table('faqs')
            ->where('category', 'Slack')
            ->where('question', self::QUESTION)
            ->where('answer', self::OLD_ANSWER)
            ->update(['answer' => self::NEW_ANSWER, 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('faqs')
            ->where('category', 'Slack')
            ->where('question', self::QUESTION)
            ->where('answer', self::NEW_ANSWER)
            ->update(['answer' => self::OLD_ANSWER, 'updated_at' => now()]);
    }
};

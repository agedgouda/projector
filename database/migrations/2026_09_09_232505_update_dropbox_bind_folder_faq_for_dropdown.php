<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const QUESTION = 'How do I bind a Dropbox folder to a project?';

    private const OLD_ANSWER = 'Once Dropbox is connected, type the folder\'s path (e.g. "/Client Intake") into the "Add A Folder" form on the organization\'s Configuration tab and pick a project — there\'s no folder picker to choose from the way there is for Slack channels, since Dropbox has no equivalent "list every folder" call; Projector resolves the typed path to Dropbox\'s own folder id itself, and tells you if that path doesn\'t exist in the connected account.';

    private const NEW_ANSWER = 'Once Dropbox is connected, pick a folder and a project from the "Add A Folder" form on the organization\'s Configuration tab — same picker experience as Slack channels. The folder dropdown lists every top-level folder in the connected account; a nested subfolder isn\'t listed and can\'t be bound directly.';

    /**
     * Only updates the row if it still has its original, un-customized text — an admin who's
     * already edited it from the /faq page keeps their own wording. See
     * database/migrations/2026_09_08_203150_update_slack_faq_file_import_answer.php for the same
     * pattern applied to a Slack FAQ row.
     */
    public function up(): void
    {
        DB::table('faqs')
            ->where('category', 'Dropbox')
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
            ->where('category', 'Dropbox')
            ->where('question', self::QUESTION)
            ->where('answer', self::NEW_ANSWER)
            ->update(['answer' => self::OLD_ANSWER, 'updated_at' => now()]);
    }
};

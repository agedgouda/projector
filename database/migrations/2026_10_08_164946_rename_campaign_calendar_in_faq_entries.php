<?php

use App\Models\Faq;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Every phrase the earlier FAQ seed migrations used for the project tab, mapped to its new
     * wording now that the tab is just "Calendar". Listed as whole phrases (not a blanket
     * "Campaign Calendar" swap) so down() can reverse exactly these — one answer already said
     * "Calendars" on its own and must stay as it is.
     *
     * @var array<string, string>
     */
    private const PHRASES = [
        'Campaign Calendar tab' => 'Calendar tab',
        'The Campaign Calendar shows' => 'The Calendar shows',
        'How do I use the Campaign Calendar?' => 'How do I use the Calendar?',
        '- Campaign Calendar — the project' => '- Calendar — the project',
    ];

    public function up(): void
    {
        $this->replace(self::PHRASES);
    }

    public function down(): void
    {
        $this->replace(array_flip(self::PHRASES));
    }

    /**
     * Keywords keep "campaign calendar" so searching the old name still finds these entries.
     *
     * @param  array<string, string>  $phrases
     */
    private function replace(array $phrases): void
    {
        Faq::query()->each(function (Faq $faq) use ($phrases): void {
            $faq->question = strtr($faq->question, $phrases);
            $faq->answer = strtr($faq->answer, $phrases);

            if ($faq->isDirty()) {
                $faq->save();
            }
        });
    }
};

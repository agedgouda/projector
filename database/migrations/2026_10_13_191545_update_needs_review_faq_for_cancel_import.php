<?php

use App\Models\Faq;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const QUESTION = 'What is the "Needs Review" list on the Import page?';

    private const FROM = 'Open it to finish the import in the same Smart Import window, confirming or correcting what the AI proposed; there\'s no need to upload the file again. Once a spreadsheet\'s layout has been confirmed, files with the same layout import automatically from then on. If you don\'t want a file, dismiss it — that can\'t be undone, and whoever uploaded it is notified.';

    private const TO = 'Click "Review & Import" on the file\'s row to finish the import in the same Smart Import window, confirming or correcting what the AI proposed; there\'s no need to upload the file again. Once a spreadsheet\'s layout has been confirmed, files with the same layout import automatically from then on. If you don\'t want a file, click "Cancel Import" on its row and confirm — the file is discarded without importing, that can\'t be undone, and whoever uploaded it is notified. It won\'t come back unless the file is changed or dropped in again.';

    /**
     * Each Needs Review row now has labelled "Review & Import" and "Cancel Import" buttons in
     * place of a clickable row and an unlabelled X.
     */
    public function up(): void
    {
        $this->replace(self::FROM, self::TO);
    }

    public function down(): void
    {
        $this->replace(self::TO, self::FROM);
    }

    private function replace(string $from, string $to): void
    {
        $faq = Faq::query()->where('question', self::QUESTION)->first();

        if ($faq === null || ! str_contains($faq->answer, $from)) {
            return;
        }

        $keywords = (string) $faq->keywords;

        $faq->update([
            'answer' => str_replace($from, $to, $faq->answer),
            'keywords' => str_contains($keywords, 'cancel import') ? $keywords : $keywords.', cancel import, dismiss, remove, don\'t import',
        ]);
    }
};

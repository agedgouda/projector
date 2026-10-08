<?php

use App\Models\Faq;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The bug report form's button now just says "Submit" (the form is titled "Get In Touch").
     */
    public function up(): void
    {
        $this->replace('Then click "Submit Bug Report".', 'Then click "Submit".');
    }

    public function down(): void
    {
        $this->replace('Then click "Submit".', 'Then click "Submit Bug Report".');
    }

    private function replace(string $from, string $to): void
    {
        $faq = Faq::query()->where('question', 'How do I report a bug?')->first();

        if ($faq !== null && str_contains($faq->answer, $from)) {
            $faq->update(['answer' => str_replace($from, $to, $faq->answer)]);
        }
    }
};

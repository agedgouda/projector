<?php

use App\Models\Faq;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The Transformation Library is now one grid of cards, each with an On/Off switch an Org Admin
     * uses to hide a transformation from their organization's pickers.
     */
    public function up(): void
    {
        $library = Faq::query()->where('question', 'How do I create my own transformation?')->first();

        if ($library !== null && Faq::query()->where('question', self::QUESTION)->doesntExist()) {
            Faq::query()
                ->where('category', 'Transformations')
                ->where('order', '>', $library->order)
                ->increment('order');

            Faq::query()->create([
                'category' => 'Transformations',
                'order' => $library->order + 1,
                'question' => self::QUESTION,
                'answer' => self::ANSWER,
                'keywords' => 'turn off, turn on, hide transformation, disable, enable, on/off, switch, transformation library',
            ]);
        }

        foreach (self::REPLACEMENTS as $question => [$from, $to]) {
            $this->replace($question, $from, $to);
        }
    }

    public function down(): void
    {
        $faq = Faq::query()->where('question', self::QUESTION)->first();

        if ($faq !== null) {
            Faq::query()
                ->where('category', 'Transformations')
                ->where('order', '>', $faq->order)
                ->decrement('order');

            $faq->delete();
        }

        foreach (self::REPLACEMENTS as $question => [$from, $to]) {
            $this->replace($question, $to, $from);
        }
    }

    private const QUESTION = 'How do I turn a transformation on or off?';

    private const ANSWER = <<<'TEXT'
Org Admins choose which transformations their organization sees. In the Transformation Library, each card has an On/Off switch in its top-right corner. Turn one off and it disappears from the "Transform" list on documents, from the saved transformations in Import Data, and from the choices when setting up a protocol step. Turned-off cards stay in the library, dimmed, so you can turn them back on at any time. The switch applies to your whole organization, and new transformations start out on.

Turning a transformation off doesn't break anything already set up: a protocol step that uses it still runs it, and "Reprocess" still re-runs it on a document it was last used on. Status Meeting: Action Item Extraction runs automatically for status meetings, so it can't be turned off.
TEXT;

    /**
     * @var array<string, array{0: string, 1: string}>
     */
    private const REPLACEMENTS = [
        'How do I run a transformation on a document?' => [
            'Pick a transformation from the list and click "Run".',
            'Pick a transformation from the list and click "Run". The list only shows transformations your organization has turned on (see "How do I turn a transformation on or off?").',
        ],
        'How do I create my own transformation?' => [
            'It lists Global Templates, which everyone can use, and your organization\'s own under My Organization.',
            'It shows every transformation as a card: the global ones, which every organization can use, and your organization\'s own. Click a card to see its details — your organization\'s own show a pencil and can be edited; global ones show a magnifying glass and are view-only.',
        ],
        'How do I create my own transformation?_duplicate' => [
            'To customize a global template, duplicate it — the copy belongs to your organization and can be edited.',
            'To customize a global transformation, click the copy icon on its card — the copy belongs to your organization and can be edited.',
        ],
    ];

    private function replace(string $question, string $from, string $to): void
    {
        $faq = Faq::query()->where('question', explode('_', $question)[0])->first();

        if ($faq !== null && str_contains($faq->answer, $from)) {
            $faq->update(['answer' => str_replace($from, $to, $faq->answer)]);
        }
    }
};

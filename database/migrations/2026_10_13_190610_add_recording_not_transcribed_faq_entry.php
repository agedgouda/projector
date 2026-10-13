<?php

use App\Models\Faq;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const CATEGORY = 'Transcripts & Meeting Notes';

    private const QUESTION = 'What if a recording doesn\'t get transcribed?';

    private const ANSWER = <<<'TEXT'
Your audio isn't thrown away. Projector keeps every recording until it has a transcript.
- If saving fails when you click Stop on "Capture Live Meeting Audio" (for example, your connection dropped), the capture box says so and keeps the recording in your browser. Click "Try Again" to save it, or "Download Audio" to keep a copy on your computer.
- If a recording was saved but couldn't be transcribed, or there's still no transcript an hour later, a yellow "This recording couldn't be transcribed" box appears at the top of the recording's transcript and its Meeting Notes. Click "Try Again" to send it through again — the transcript and Meeting Notes fill in when it's done. Click "Download Audio" to save a copy of the recording.
- If a capture was cut off before anyone clicked Stop (for example, the browser tab was closed), whatever had already uploaded is saved automatically about two hours later as "Recovered recording — <date and time>" on the project's Transcripts tab, and transcribed like any other recording.

The yellow box is shown only to Org Admins and Project Leads. A recording too large to transcribe can't be tried again, but you can still download it.
TEXT;

    /**
     * The Try Again / Download Audio safety nets for recordings: the capture box's save-failed
     * state, the RecordingProblemPanel on the transcript and Meeting Notes pages, and the
     * automatic "Recovered recording" from app:prune-abandoned-tus-uploads. Listed after the
     * phone recording entry.
     */
    public function up(): void
    {
        $phone = Faq::query()->where('question', 'Can I record a meeting on my phone?')->first();

        if ($phone === null || Faq::query()->where('question', self::QUESTION)->exists()) {
            return;
        }

        Faq::query()
            ->where('category', self::CATEGORY)
            ->where('order', '>', $phone->order)
            ->increment('order');

        Faq::query()->create([
            'category' => self::CATEGORY,
            'order' => $phone->order + 1,
            'question' => self::QUESTION,
            'answer' => self::ANSWER,
            'keywords' => 'recording, failed, stuck, not transcribed, try again, retry, download audio, backup, recovered recording, capture, lost, failed to fetch',
        ]);
    }

    public function down(): void
    {
        $faq = Faq::query()->where('question', self::QUESTION)->first();

        if ($faq === null) {
            return;
        }

        Faq::query()
            ->where('category', self::CATEGORY)
            ->where('order', '>', $faq->order)
            ->decrement('order');

        $faq->delete();
    }
};

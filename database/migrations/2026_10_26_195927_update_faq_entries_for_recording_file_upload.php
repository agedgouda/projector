<?php

use App\Models\Faq;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const UPLOAD_HOW = 'click "Import Document" on the Documentation tab (or use the Import page), leave "Import As" set to Transcription, and choose "Upload Meeting Recording (audio or video)". Any audio file, or an .mp4 or .mov video, up to 200 MB — for example a Google Meet recording downloaded from Google Drive. It\'s transcribed after it uploads.';

    /**
     * @var list<array{question: string, from: string, to: string, keywords: string}>
     */
    private array $changes;

    public function __construct()
    {
        $this->changes = [
            [
                'question' => 'What are the ways to get a meeting transcript into a project?',
                'from' => '- Paste it in —',
                'to' => '- Upload a recording file — '.self::UPLOAD_HOW."\n- Paste it in —",
                'keywords' => 'upload recording, mp4, video, audio file',
            ],
            [
                'question' => 'What are the ways to get a meeting transcript into a project?',
                'from' => 'The import options inside Projector (the first three)',
                'to' => 'The import options inside Projector (the first four)',
                'keywords' => '',
            ],
            [
                'question' => 'What are all the ways to import into Projector?',
                'from' => '- Meeting recordings — on a project\'s Transcripts tab, import a recording from your organization\'s meeting provider, or capture a meeting live from a browser tab.',
                'to' => '- Meeting recordings — on a project\'s Transcripts tab, import a recording from your organization\'s meeting provider, or capture a meeting live from a browser tab. A recording you already have as a file can be uploaded through "Import Document".',
                'keywords' => 'recording file, mp4',
            ],
            [
                'question' => 'How do I import a document such as a Google Doc, Word file, or text file?',
                'from' => 'or "Upload Word or Text File (.docx, .txt)", up to 10 MB.',
                'to' => 'or "Upload Word or Text File (.docx, .txt)", up to 10 MB. To bring in a meeting recording you have as a file, choose "Upload Meeting Recording (audio or video)" instead — any audio file, or an .mp4 or .mov video, up to 200 MB. It\'s always imported as a Transcription, so this option is unavailable when "Import As" is set to anything else.',
                'keywords' => 'upload recording, audio, video, mp4, mov',
            ],
            [
                'question' => 'How do I import a meeting recording?',
                'from' => 'use its dismiss button so it isn\'t imported by mistake.',
                'to' => 'use its dismiss button so it isn\'t imported by mistake.'."\n\n".'Google Meet meetings that were recorded without transcripts turned on are listed too, marked "Recording only". Import one the same way — Projector downloads the recording from Google Drive and transcribes it itself, so it takes longer than a meeting Meet already transcribed. This needs your Google Workspace admin to have added the drive.meet.readonly scope to Projector\'s service account (see the Google Meet Setup Guide); until then, importing one shows what to add, and you can click "Try Again" once it\'s added. To have every meeting transcribed so this isn\'t needed, a Workspace admin can turn on "Meetings are transcribed by default" — also in the Setup Guide.'."\n\n".'Only Google Meet meetings organized by the Workspace user Projector is set up to act as are listed. A recording you have as a file can always be uploaded instead: '.self::UPLOAD_HOW,
                'keywords' => 'recording only, no transcript, google drive, upload recording, mp4',
            ],
        ];
    }

    /**
     * Meeting recordings can now be uploaded as a file from the "Import a Document" window, and
     * Google Meet meetings recorded without transcripts are listed ("Recording only") and
     * transcribed by Projector on import.
     */
    public function up(): void
    {
        foreach ($this->changes as $change) {
            $this->replace($change['question'], $change['from'], $change['to'], $change['keywords']);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->changes) as $change) {
            $this->replace($change['question'], $change['to'], $change['from'], $change['keywords'], removeKeywords: true);
        }
    }

    private function replace(string $question, string $from, string $to, string $addedKeywords, bool $removeKeywords = false): void
    {
        $faq = Faq::query()->where('question', $question)->first();

        if ($faq === null || ! str_contains($faq->answer, $from)) {
            return;
        }

        $keywords = (string) $faq->keywords;

        if ($addedKeywords !== '') {
            $keywords = match (true) {
                $removeKeywords => str_replace(', '.$addedKeywords, '', $keywords),
                str_contains($keywords, $addedKeywords) => $keywords,
                default => $keywords.', '.$addedKeywords,
            };
        }

        $faq->update([
            'answer' => str_replace($from, $to, $faq->answer),
            'keywords' => $keywords,
        ]);
    }
};

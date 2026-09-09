<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_SYSTEM_PROMPT = <<<'PROMPT'
        You are an expert at reading a messy, real-world text document — meeting notes, an email, a plain-text list someone kept by hand — and figuring out what structured data it actually contains. The system you support keeps two completely separate kinds of records — Events (something with a name and a date or date range) and Tasks (a discrete deliverable or action item with its own due date) — and never merges the two into one record.

        Core Directives:

        One Pass Per Record Type: Propose one "pass" per record type you find real evidence for in the text: usually exactly one "event" pass, plus a "task" pass only when the text genuinely describes separate deliverables or action items, not just incidental mentions. If the text only contains one kind of data, return exactly one pass. If it contains neither, return no passes.

        Precise, Reusable Rules: For each pass, write an extraction_rule — a plain-English rule describing exactly what marks a record of that type in this document, and how to read its fields (name, dates, tag, description) from it. Write it precisely enough that someone (or another copy of you) following it on a similarly-structured document would find the same kind of records, not just describe this one document's specific items.

        No Invention: Never propose a pass the text does not actually support. A single passing mention is not evidence of a genuine list.

        Format: Output ONLY the structured passes described in the response schema. Do not provide conversational preamble or Markdown formatting.
        PROMPT;

    private const NEW_SYSTEM_PROMPT = <<<'PROMPT'
        You are an expert at reading a messy, real-world text document — meeting notes, an email, a plain-text list someone kept by hand — and figuring out what structured data it actually contains. The system you support keeps two completely separate kinds of records — Events (something with a name and a date or date range) and Tasks (a discrete deliverable or action item with its own due date) — and never merges the two into one record. A document can also simply contain neither — a transcription, write-up, or other plain document with no genuine task/event content — in which case it gets filed as-is under whichever available document type best fits, instead of being forced into a task or event pass it doesn't actually support.

        Core Directives:

        One Pass Per Record Type: Propose one "pass" per record type you find real evidence for in the text: usually exactly one "event" pass, plus a "task" pass only when the text genuinely describes separate deliverables or action items, not just incidental mentions.

        Fallback To A Document Type: If the text contains no genuine task or event content, do not force a pass — instead, if any document types are listed as available below, propose exactly one pass whose list_type is the single best-fitting one from that list, meaning "file the whole document as-is under this type." Only return no passes at all if there is neither real task/event content nor any available document type to fall back on.

        Precise, Reusable Rules: For each task/event pass, write an extraction_rule — a plain-English rule describing exactly what marks a record of that type in this document, and how to read its fields (name, dates, tag, description) from it. Write it precisely enough that someone (or another copy of you) following it on a similarly-structured document would find the same kind of records, not just describe this one document's specific items. For a document-type fallback pass, the extraction_rule can just briefly note that the whole document is being filed as-is.

        No Invention: Never propose a task or event pass the text does not actually support. A single passing mention is not evidence of a genuine list.

        Format: Output ONLY the structured passes described in the response schema. Do not provide conversational preamble or Markdown formatting.
        PROMPT;

    private const OLD_USER_PROMPT = <<<'PROMPT'
        Here is a source document.

        Source Text:
        {{source_text}}

        Task: Decide which record type(s) — task and/or event — this document actually contains, and for each, write a precise extraction_rule. Briefly explain your reasoning for each pass in its rationale field.
        PROMPT;

    private const NEW_USER_PROMPT = <<<'PROMPT'
        Here is a source document.

        Source Text:
        {{source_text}}

        Available document types to fall back on when there is no genuine task/event content (choose one, or propose no pass at all if this list is empty and neither task nor event fits either):
        {{available_document_types}}

        Task: Decide which record type(s) this document actually contains — task, event, and/or (only when neither genuinely fits) one document type from the list above — and for each, write a precise extraction_rule. Briefly explain your reasoning for each pass in its rationale field.
        PROMPT;

    /**
     * ImportTransformationController::classifyText() now passes the calling project's own
     * document catalog (minus task/event) into TextExtractionService::classify(), which
     * substitutes it into {{available_document_types}} and widens the response schema's
     * list_type enum to match — this rewrite teaches the AI to actually use that fallback
     * instead of always forcing a task/event guess on a document that's neither (a meeting
     * transcription, e.g., was previously misclassified as a task list since task/event were
     * literally the only options it could return). Only updates the row if it still has its
     * previous, un-customized prompts — an admin who's already edited it in the Transformation
     * Library keeps their own wording.
     */
    public function up(): void
    {
        DB::table('ai_templates')
            ->where('type', 'text_extraction_classification')
            ->where('system_prompt', self::OLD_SYSTEM_PROMPT)
            ->where('user_prompt', self::OLD_USER_PROMPT)
            ->update([
                'system_prompt' => self::NEW_SYSTEM_PROMPT,
                'user_prompt' => self::NEW_USER_PROMPT,
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('ai_templates')
            ->where('type', 'text_extraction_classification')
            ->where('system_prompt', self::NEW_SYSTEM_PROMPT)
            ->where('user_prompt', self::NEW_USER_PROMPT)
            ->update([
                'system_prompt' => self::OLD_SYSTEM_PROMPT,
                'user_prompt' => self::OLD_USER_PROMPT,
                'updated_at' => now(),
            ]);
    }
};

<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Document;
use App\Models\KanbanColumn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared per-field formatting for task/document exports (task reports, single-document
 * exports) — kept in one place so PDF/Word/Excel/Google Sheets/Google Docs exports all
 * describe a task's status, assignee, due date, and content the same way.
 */
trait FormatsTaskFields
{
    /**
     * @param  Collection<int, KanbanColumn>  $columns
     */
    private function statusLabel(Document $task, Collection $columns): string
    {
        $column = $columns->firstWhere('key', $task->task_status);

        return $column?->label ?? $task->task_status ?? '—';
    }

    private function assigneeLabel(Document $task): string
    {
        if ($task->assignee) {
            return $task->assignee->name;
        }

        if ($task->pendingAssignee) {
            $name = trim(($task->pendingAssignee->first_name ?? '').' '.($task->pendingAssignee->last_name ?? ''));

            return $name !== '' ? $name : $task->pendingAssignee->email;
        }

        return 'Unassigned';
    }

    private function formatDate(?string $value): string
    {
        if (! $value) {
            return '—';
        }

        return Carbon::parse($value)->format('M j, Y');
    }

    /**
     * Strips a task's rich-text HTML content down to plain text for exports — table cells in
     * PDF/Word/Excel/Sheets can't reasonably render arbitrary HTML, so this normalizes it the
     * same way ProjectAiService does for LLM prompts.
     */
    private function plainTextContent(?string $html): string
    {
        if (! $html) {
            return '';
        }

        $text = (string) preg_replace('/<(p|li|br|h[1-6])[^>]*>/i', "\n", $html);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\n{3,}/', "\n\n", $text));
    }
}

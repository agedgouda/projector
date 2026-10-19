<?php

namespace App\Services\Demo;

use App\Models\AiTemplate;
use App\Services\Ai\Drivers\OpenAiLlmDriver;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Runs the built-in "Create Tasks" transformation over text from the public demo page.
 *
 * It mirrors ProjectAiService::callLlm() but has no project: the team and tags come from the
 * request instead of the database, nothing is saved, and the system OpenAI driver is used
 * directly because there's no organization whose AI settings could apply.
 */
class DemoTaskExtractor
{
    public const TEMPLATE_NAME = 'Create Tasks';

    /**
     * @var list<string>
     */
    public const TAGS = ['Design', 'Development', 'Content', 'Client', 'QA', 'Launch'];

    private const OUTPUT_KEY = 'description';

    private const MAX_TASKS = 25;

    public function __construct(private readonly OpenAiLlmDriver $llmDriver) {}

    /**
     * @param  list<string>  $team
     * @return list<array{title: string, description: string, criteria: list<string>, priority: string, due_date: string|null, assignee: string|null, tags: list<string>}>
     */
    public function extract(string $notes, CarbonInterface $meetingDate, array $team): array
    {
        $template = AiTemplate::query()
            ->whereNull('organization_id')
            ->where('name', self::TEMPLATE_NAME)
            ->first();

        if ($template === null) {
            throw new RuntimeException('The built-in "'.self::TEMPLATE_NAME.'" transformation is missing.');
        }

        $replacements = [
            '{{today}}' => $meetingDate->toDateString(),
            '{{available_tags}}' => implode(', ', array_map(fn (string $tag): string => '"'.$tag.'"', self::TAGS)),
            '{{output_key}}' => self::OUTPUT_KEY,
            '{{input}}' => $notes,
        ];

        $systemPrompt = $this->htmlToPlainText(str_replace(array_keys($replacements), array_values($replacements), (string) $template->system_prompt))
            ."\n\nRespond only in English. Do not use any non-English words, phrases, or characters, including in headings, labels, or examples.";

        $userPrompt = str_replace(array_keys($replacements), array_values($replacements), (string) $template->user_prompt)
            ."\n\nCRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: \"title\", \"".self::OUTPUT_KEY.'", "criteria", and "priority" (one of "low", "medium", or "high"). Also include "due_date" (an ISO 8601 date string YYYY-MM-DD, or null if no date is mentioned) and "start_date" (an ISO 8601 date string YYYY-MM-DD, or null if the item doesn\'t span a range of dates).';

        if ($team !== []) {
            $names = implode(', ', array_map(fn (string $name): string => '"'.$name.'"', $team));
            $userPrompt .= " Also include \"assignee_name\": if an item is clearly assigned to one of the people mentioned in the source text — {$names} — set it to their exact name as given; otherwise set it to null. Never use a name that isn't in that list.";
            $systemPrompt .= "\n\nException to any instruction above about not extracting assignee/owner information, or about which JSON keys are allowed: for this request only, also follow the assignee_name instructions given below.";
        }

        $result = $this->llmDriver->call($systemPrompt, $userPrompt);

        if (($result['status'] ?? '') !== 'success') {
            Log::error('Demo task extraction failed', ['error' => $result['message'] ?? 'Unknown error']);

            throw new RuntimeException('AI extraction failed.');
        }

        $content = $result['content'] ?? [];
        $tasks = $this->normalizeTasks(is_array($content) ? $content : [], $team);

        // Usage only — the submitted text and the output are never logged.
        Log::info('Demo task extraction', [
            'driver' => $result['driver'] ?? null,
            'model' => $result['model'] ?? null,
            'input_tokens' => $result['input_tokens'] ?? 0,
            'output_tokens' => $result['output_tokens'] ?? 0,
            'tasks' => count($tasks),
        ]);

        return $tasks;
    }

    /**
     * Keeps only well-formed fields, and only assignees and tags from the allowed lists, since
     * the model's output goes straight to an anonymous visitor's browser.
     *
     * @param  array<mixed>  $items
     * @param  list<string>  $team
     * @return list<array{title: string, description: string, criteria: list<string>, priority: string, due_date: string|null, assignee: string|null, tags: list<string>}>
     */
    private function normalizeTasks(array $items, array $team): array
    {
        $teamByLowerName = [];
        foreach ($team as $name) {
            $teamByLowerName[mb_strtolower($name)] = $name;
        }

        $tagsByLowerName = [];
        foreach (self::TAGS as $tag) {
            $tagsByLowerName[mb_strtolower($tag)] = $tag;
        }

        $tasks = [];

        foreach (array_slice(array_values($items), 0, self::MAX_TASKS) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->cleanString($item['title'] ?? null, 200);

            if ($title === '') {
                continue;
            }

            $description = $this->cleanString($item[self::OUTPUT_KEY] ?? $item['content'] ?? null, 2000);

            $criteria = [];
            foreach (is_array($item['criteria'] ?? null) ? $item['criteria'] : [] as $criterion) {
                $cleaned = $this->cleanString($criterion, 300);

                if ($cleaned !== '') {
                    $criteria[] = $cleaned;
                }
            }

            $priority = in_array($item['priority'] ?? null, ['low', 'medium', 'high'], true) ? $item['priority'] : 'medium';

            $dueDate = is_string($item['due_date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $item['due_date']) === 1
                ? $item['due_date']
                : null;

            $assigneeName = is_string($item['assignee_name'] ?? null) ? mb_strtolower(trim($item['assignee_name'])) : '';

            $tags = [];
            foreach (is_array($item['tag_names'] ?? null) ? $item['tag_names'] : [] as $tagName) {
                $tag = is_string($tagName) ? ($tagsByLowerName[mb_strtolower(trim($tagName))] ?? null) : null;

                if ($tag !== null && ! in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }

            $tasks[] = [
                'title' => $title,
                'description' => $description,
                'criteria' => array_slice($criteria, 0, 5),
                'priority' => $priority,
                'due_date' => $dueDate,
                'assignee' => $teamByLowerName[$assigneeName] ?? null,
                'tags' => $tags,
            ];
        }

        return $tasks;
    }

    private function cleanString(mixed $value, int $maxLength): string
    {
        return is_string($value) ? mb_substr(trim(strip_tags($value)), 0, $maxLength) : '';
    }

    private function htmlToPlainText(string $html): string
    {
        $text = (string) preg_replace('/<(p|li|br|h[1-6])[^>]*>/i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\n{3,}/', "\n\n", $text));
    }
}

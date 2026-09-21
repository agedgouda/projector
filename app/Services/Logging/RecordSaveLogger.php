<?php

namespace App\Services\Logging;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LogLevel;

/**
 * Writes the trail for edits to task/document records — every request that tries to change one,
 * what it changed, and every way it can fail — to its own log file (storage/logs/record-saves.log),
 * so "the toast said saved but it wasn't" can be reconstructed line by line instead of guessed at.
 *
 * Anything at warning level or above is also written to the application's main log, since that's
 * where a person looking for an error looks first.
 *
 * Every line carries the `save_id` the browser generated for that save (X-Save-Id header, see
 * saveRecord() in serialVisits.ts) so the browser's own report of a failure, the request that
 * arrived, the change made, and the response sent can all be lined up.
 */
class RecordSaveLogger
{
    /**
     * Attributes worth recording the values of. Everything else — notably `content`, which can be
     * long and private — is recorded only as a length.
     *
     * @var list<string>
     */
    public const TRACKED_ATTRIBUTES = [
        'task_status', 'priority', 'due_at', 'external_due_at', 'start_at',
        'assignee_id', 'pending_assignee_invitation_id', 'name', 'type', 'project_id',
    ];

    /**
     * The id the browser gave this save, or a fresh one for a request that didn't send any.
     */
    public function saveId(): string
    {
        $request = request();

        $sent = $request->header('X-Save-Id');

        if (is_string($sent) && $sent !== '') {
            return Str::limit($sent, 64, '');
        }

        if (! $request->attributes->has('save_id')) {
            $request->attributes->set('save_id', (string) Str::uuid());
        }

        return (string) $request->attributes->get('save_id');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(string $level, string $event, array $context = []): void
    {
        // A caller may supply its own save_id (the browser's report of a save that never arrived is
        // logged under the id of the save it's about, not the id of the request that carried it).
        $context = array_merge(['save_id' => $this->saveId(), 'user_id' => auth()->id()], $context);

        Log::channel('record_saves')->log($level, $event, $context);

        if (in_array($level, [LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL], true)) {
            Log::log($level, "record save: {$event}", $context);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $event, array $context = []): void
    {
        $this->log(LogLevel::INFO, $event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $event, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $event, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $event, $context);
    }

    /**
     * A request's input reduced to what's safe and useful to log: tracked attributes by value,
     * tag ids, and long text (`content`, `custom_prompt`) by length only.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function describeInput(array $input): array
    {
        $described = [];

        foreach ($input as $key => $value) {
            if (in_array($key, self::TRACKED_ATTRIBUTES, true)) {
                $described[$key] = is_string($value) ? Str::limit($value, 120) : $value;
            } elseif ($key === 'category_ids' && is_array($value)) {
                $described[$key] = array_values($value);
            } elseif (in_array($key, ['content', 'custom_prompt'], true) && is_string($value)) {
                $described[$key.'_length'] = strlen($value);
            } elseif (! str_starts_with((string) $key, '_')) {
                $described[$key] = '(not recorded)';
            }
        }

        return $described;
    }
}

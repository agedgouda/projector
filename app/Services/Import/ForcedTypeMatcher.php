<?php

namespace App\Services\Import;

use App\Models\DocumentTypeDefinition;
use App\Models\Project;

/**
 * Looks for a single, unambiguous signal naming one of a project's own document types (Meeting
 * Notes, Transcription, or any other type the project uses) — an explicit, deterministic
 * override an uploader can use instead of waiting on AI classification and a review step.
 * Shared by every import source (Slack, Dropbox, ...) so this rule works identically no matter
 * where the file came from — originally extracted from ImportSlackFile, which was the only
 * caller until Dropbox needed the exact same matching.
 *
 * Two kinds of signal are recognized:
 * - #tag text (e.g. "#meeting-notes") anywhere in a set of free-text strings — a filename, a
 *   Slack message, or any other text an upload can carry alongside it.
 * - An exact folder name (e.g. a Dropbox subfolder) matching a type's short_code — no "#"
 *   needed, since choosing a folder to drop a file into is already a deliberate act, unlike a
 *   stray word appearing in text.
 *
 * Task and event are deliberately never matchable this way: forcing either still leaves an
 * extraction_rule to work out, which is exactly what the AI classification step (still needed
 * either way) produces — there's no step to skip for those two the way there is for "just file
 * it as this type verbatim". More than one distinct type signaled at once is treated as no
 * match at all, rather than guessing which one the uploader meant.
 */
class ForcedTypeMatcher
{
    /**
     * @param  list<string>  $tagSignals  Free text searched for #tag mentions — a filename, a
     *                                    Slack message, or any other caller-supplied text.
     */
    public function match(Project $project, array $tagSignals, ?string $exactFolderName = null): ?DocumentTypeDefinition
    {
        $tags = [];
        foreach ($tagSignals as $signal) {
            $tags = [...$tags, ...$this->extractHashtags($signal)];
        }

        $catalog = $project->documentTypeCatalog()
            ->reject(fn (DocumentTypeDefinition $definition) => in_array($definition->key, ['task', 'event'], true));

        $matches = $catalog->filter(function (DocumentTypeDefinition $definition) use ($tags, $exactFolderName) {
            if (in_array($definition->short_code, $tags, true)) {
                return true;
            }

            return $exactFolderName !== null && strcasecmp($definition->short_code, $exactFolderName) === 0;
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * @return list<string>
     */
    private function extractHashtags(string $text): array
    {
        preg_match_all('/#([a-z0-9-]+)/i', $text, $matches);

        return array_map(strtolower(...), $matches[1]);
    }
}

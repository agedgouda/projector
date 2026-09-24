<?php

namespace App\Observers;

use App\Events\DocumentProcessingUpdate;
use App\Events\DocumentVectorized;
use App\Jobs\GenerateDocumentEmbedding;
use App\Jobs\ProcessDocumentAI;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Services\Logging\RecordSaveLogger;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class DocumentObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * task_list_import/event_list_import documents store their content as a JSON dump of every
     * imported row (see ImportTaskList::finish()) — not human-readable text, so embedding it is
     * meaningless, and a large import's JSON routinely exceeds OpenAI's 8192-token embedding
     * input limit, permanently failing GenerateDocumentEmbedding for every such import.
     *
     * @var list<string>
     */
    private const NON_VECTORIZABLE_TYPES = ['task_list_import', 'event_list_import'];

    public function created(Document $document): void
    {
        // 1. Priority: the universal, protocol-independent Notes -> Action Items step.
        // This is the only transition that ever fires automatically — every step after
        // it is an explicit, user-reviewed choice (see DocumentController::transition()),
        // or an automatic continuation of a protocol locked in by an earlier choice.
        // No override is passed here — ProjectAiService::process() detects the intake type
        // itself, so reprocessing (which also calls process() without an override) stays
        // consistent with what happens on creation.
        // Only root documents (not AI-generated outputs of a previous step) trigger this.
        $isIntake = $document->type === config('workflow.intake_key');

        if ($isIntake && is_null($document->processed_at) && is_null($document->parent_id)) {
            ProcessDocumentAI::dispatchUnlessProcessing($document);

            return;
        }

        // 2. Secondary: Standard Vectorization
        // For manually added context, jump straight to embedding.
        if (! empty($document->content) && is_null($document->embedding) && ! in_array($document->type, self::NON_VECTORIZABLE_TYPES, true)) {
            GenerateDocumentEmbedding::dispatch($document);
        }

        // 3. Live Kanban update: a root-level task (manually entered via the UI, or created via
        // a Slack slash command/shortcut) has no page load of its own to carry it onto an
        // already-open board — broadcast it the same way ProcessDocumentAI already does for
        // AI-generated tasks, so useAiProcessing.ts's onDocumentUpdated patches it in live.
        // Scoped to $document->parent_id === null so this never double-fires alongside
        // ProcessDocumentAI's own batch broadcast for AI-generated child tasks (which always
        // have a parent_id) — those already have their own dedicated progress/reload path.
        // An empty statusMessage deliberately skips every progress/success/error branch in the
        // listener (parseProcessingStatus treats a falsy statusMessage as "nothing to report") —
        // there's no AI process happening here to narrate, just a document to add to the board.
        if ($this->isTaskType($document) && is_null($document->parent_id)) {
            $this->broadcastWithoutFailingTheSave(fn () => event(new DocumentProcessingUpdate($document, '', 0)));
        }
    }

    public function creating(Document $document): void
    {
        $isTask = $this->isTaskType($document);

        // If it's a taskable type and no status was given, default to 'todo'. Checked against
        // task_status itself (not the legacy `status` column, which nothing sets anymore and
        // is always null) — this used to unconditionally overwrite an explicitly-chosen
        // task_status back to 'todo' on every single creation, since `is_null($document->status)`
        // was always true.
        // PHPStan infers task_status as non-nullable from the migration's DB-level NOT NULL
        // (with a default), but that default only applies at INSERT time — on this in-memory,
        // not-yet-created model, the attribute is genuinely unset/null whenever the caller
        // didn't explicitly assign it, exactly the case this check exists to catch.
        // @phpstan-ignore function.impossibleType, booleanAnd.alwaysFalse
        if ($isTask && is_null($document->task_status)) {
            $document->status = 'todo';
            $document->task_status = 'todo'; // Keep both in sync for your board
        }

        // Every task gets an initial status_changed_at the moment it's created — not just ones
        // defaulted to 'todo' above, since a task created with an explicit status still has a
        // real "since when" for that status. wasChanged()/isDirty() are meaningless here (a
        // brand-new model has no prior state to diff against), so this is unconditional rather
        // than gated on a change — see updating() below for the case that actually changes.
        if ($isTask && is_null($document->status_changed_at)) {
            $document->status_changed_at = now();
        }

        // A root-level task's content is complete the moment it's typed/entered — it's never
        // waiting on an AI step the way a generated child document is — so it should never read
        // as "processing" to useAiProcessing.ts's `processed_at === null` check. Root-level only
        // (matching the created() broadcast above): an AI-generated child task deliberately
        // starts with processed_at null, since ProcessDocumentAI/GenerateDocumentEmbedding own
        // stamping it once *that* document's own generation actually finishes.
        if ($isTask && is_null($document->parent_id) && is_null($document->processed_at)) {
            $document->processed_at = now();
        }
    }

    /**
     * Stamps status_changed_at whenever task_status actually changes — covers every write site
     * (the full document edit form, the Kanban/task-attribute PATCH, and the bulk import/Slack
     * jobs that write via forceFill()) with no per-call-site code, the same reason updated()
     * below reacts to specific fields via the model rather than each controller/job stamping it
     * individually.
     *
     * Deliberately isDirty() in updating() here, not wasChanged() in updated() like the
     * content/processed_at branches below — those defer to after the transaction commits
     * because they trigger external side effects (a dispatched job, a broadcast event) that
     * must not fire if the save rolls back. A plain column has no such requirement, and setting
     * it here lets it ride into the same UPDATE statement already in flight (exactly like
     * editor_id, set the same way in Document::booted()) instead of costing a second, wasted
     * query the way setting it in updated() would (dirty-tracking has already reset by then).
     */
    public function updating(Document $document): void
    {
        if ($this->isTaskType($document) && $document->isDirty('task_status')) {
            $document->status_changed_at = now();
        }
    }

    /**
     * Determine if this type is a task from the shared document type catalog — not the
     * project's own protocol, since a document can be produced by any protocol's recipe
     * regardless of which protocol its project uses.
     */
    private function isTaskType(Document $document): bool
    {
        $catalog = DocumentTypeDefinition::catalogForOrganization($document->project?->organization_id);
        $definition = $catalog->get($document->type);

        return $definition instanceof DocumentTypeDefinition && $definition->is_task;
    }

    /**
     * Routes whose controllers already log exactly what they changed (see
     * DocumentController::logApplied()), so this listener doesn't repeat it.
     *
     * @var list<string>
     */
    private const SELF_LOGGING_ROUTES = ['projects.documents.updateAttributes', 'projects.documents.update'];

    /**
     * Records every change to a tracked attribute (status, assignee, dates, priority…) made by
     * anything *other* than those two controllers — a queued job, an import, the move-to-board
     * endpoint, a command — so a value that changes, or is changed back, without a person's edit
     * behind it shows up in the record-saves log with what wrote it.
     */
    private function logChangeFromOtherWriters(Document $document): void
    {
        $changed = array_intersect_key($document->getChanges(), array_flip(RecordSaveLogger::TRACKED_ATTRIBUTES));

        if ($changed === []) {
            return;
        }

        $route = request()->route()?->getName();

        if (in_array($route, self::SELF_LOGGING_ROUTES, true)) {
            return;
        }

        app(RecordSaveLogger::class)->info('changed by other writer', [
            'document_id' => $document->id,
            'source' => app()->runningInConsole() ? 'console/queue: '.($_SERVER['argv'][1] ?? 'unknown') : 'web: '.($route ?? request()->path()),
            'changed' => $changed,
            'previous' => array_intersect_key($document->getPrevious(), $changed),
        ]);
    }

    public function updated(Document $document): void
    {
        $this->logChangeFromOtherWriters($document);

        // wasChanged(), not isDirty() — this class implements ShouldHandleEventsAfterCommit,
        // so this listener only actually runs after the enclosing transaction commits, by
        // which point save() has already called syncOriginal() and isDirty() would always
        // read false. wasChanged() stays accurate post-sync, which is the whole reason it
        // exists for use from inside a model event listener like this one.
        if ($document->wasChanged('content') && ! in_array($document->type, self::NON_VECTORIZABLE_TYPES, true)) {
            GenerateDocumentEmbedding::dispatch($document);
        }

        // Broadcast when AI processing finishes
        if ($document->wasChanged('processed_at') && $document->processed_at) {
            $this->broadcastWithoutFailingTheSave(fn () => broadcast(new DocumentVectorized($document))->toOthers());
        }
    }

    /**
     * Handle the Document "deleted" event.
     */
    public function deleted(Document $document): void
    {
        //
    }

    /**
     * Handle the Document "restored" event.
     */
    public function restored(Document $document): void
    {
        //
    }

    /**
     * Handle the Document "force deleted" event.
     */
    public function forceDeleted(Document $document): void
    {
        //
    }

    /**
     * Sends a live-update broadcast without letting a broadcasting outage fail the save that
     * triggered it. The record is already stored by the time this runs, so a failed broadcast
     * only means other open screens don't update live — reporting an error for the save itself
     * would tell the person it didn't work when it did.
     *
     * @param  \Closure(): mixed  $broadcast
     */
    private function broadcastWithoutFailingTheSave(\Closure $broadcast): void
    {
        try {
            $broadcast();
        } catch (BroadcastException $exception) {
            report($exception);
        }
    }
}

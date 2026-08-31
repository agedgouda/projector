import { INTAKE_KEY, useWorkflow } from '@/composables/useWorkflow';
import { parseProcessingStatus } from '@/lib/aiProcessingStatus';
import {
    redirectIfLoggedOut,
    redirectIfSessionExpiredError,
} from '@/lib/sessionExpiry';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import { router, useForm } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import axios from 'axios';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

// Safety net for a missed .DocumentProcessingUpdate broadcast (dropped/reconnected socket,
// tab backgrounded during the job, etc.) — without this, isProcessingLive has no other way
// to ever become false again, since it's otherwise cleared exclusively by that one event.
// 15s keeps this cheap in the common case (the broadcast almost always wins the race and
// clears the interval before it ever fires) while still self-correcting within a bounded
// time when it doesn't.
const PROCESSING_POLL_INTERVAL_MS = 15000;

export function useDocumentForm(project: Project, item: ExtendedDocument) {
    const isEditing = ref(false);
    const isDeleteModalOpen = ref(false);
    const isDeleting = ref(false);
    const isReprocessPromptOpen = ref(false);
    const isReprocessing = ref(false);

    // Live status while a dispatched reprocess job is actually running — spans from
    // dispatch until the job's own success/error broadcast arrives, same messages and
    // classification as the project tree view (see useAiProcessing.ts).
    const isProcessingLive = ref(false);
    const processingMessage = ref<string | null>(null);
    const aiProgress = ref<number>(0);
    let creepInterval: ReturnType<typeof setInterval> | null = null;

    // Same "creep" animation as useAiProcessing.ts: nudges the bar forward on its own between
    // broadcasts so it doesn't sit dead still during any gap, capped short of the next real
    // checkpoint so an actual update always visibly overtakes it.
    const stopCreep = () => {
        if (creepInterval) {
            clearInterval(creepInterval);
            creepInterval = null;
        }
    };

    watch(aiProgress, (newVal) => {
        stopCreep();
        if (newVal > 0 && newVal < 90) {
            creepInterval = setInterval(() => {
                if (aiProgress.value < newVal + 15 && aiProgress.value < 95) {
                    aiProgress.value += 0.5;
                }
            }, 1000);
        }
    });

    onBeforeUnmount(stopCreep);

    let processingPollTimer: ReturnType<typeof setInterval> | null = null;

    const stopProcessingPoll = () => {
        if (processingPollTimer) {
            clearInterval(processingPollTimer);
            processingPollTimer = null;
        }
    };

    const startProcessingPoll = () => {
        stopProcessingPoll();
        processingPollTimer = setInterval(() => {
            // Re-fetches just `item` — syncSidebarFields (called from Show.vue's watcher on
            // the replaced prop) is what actually notices processed_at advanced and clears
            // isProcessingLive; this timer's only job is to trigger that re-fetch.
            router.reload({
                only: ['item'],
                preserveScroll: true,
                preserveState: true,
            });
        }, PROCESSING_POLL_INTERVAL_MS);
    };

    // How many child documents a run against *this* document just produced, so their own
    // embedding-progress broadcasts ("Synthesizing Document Heuristics...", "Finalizing Vector
    // Integration...") keep updating processingMessage here too, instead of it freezing on
    // whatever this document's own last step said — mirrors useAiProcessing.ts's batchSourceId/
    // batchTotal, adapted from "any document in the whole tree" to "this document's own new
    // children" since this composable only ever tracks a single document (so the source id is
    // always just `item.id`, no separate ref needed for it). Deliberately just a count rather
    // than the exact child IDs — a large batch (100+ generated documents) can make that array
    // alone exceed the broadcaster's payload limit — since every per-child broadcast already
    // carries its own parent_id, which is all that's needed to recognize membership;
    // completedChildIds is populated purely from those broadcasts as they arrive.
    const trackedChildTotal = ref(0);
    const completedChildIds = ref<Set<string>>(new Set());

    const clearProcessingState = () => {
        isProcessingLive.value = false;
        processingMessage.value = null;
        aiProgress.value = 0;
        trackedChildTotal.value = 0;
        completedChildIds.value = new Set();
        stopCreep();
        stopProcessingPoll();
    };

    // Summarizes each new child's own progress as "N of M" instead of parroting its raw
    // "Synthesizing.../Finalizing..." text one child at a time with no sense of overall
    // progress once there's more than one — exactly useAiProcessing.ts's batchProgressMessage
    // (same "remaining of total" phrasing and per-child-name variant), just counting down from
    // this composable's own trackedChildTotal instead of filtering a shared allDocs Map.
    const batchProgressMessage = (
        currentDocName?: string | null,
    ): string | null => {
        if (trackedChildTotal.value === 0) return null;
        const remaining =
            trackedChildTotal.value - completedChildIds.value.size;
        if (remaining <= 0) return null;
        const countLabel = `${remaining} of ${trackedChildTotal.value}`;
        return currentDocName
            ? `Finalizing "${currentDocName}" (${countLabel})`
            : `Finalizing ${countLabel}`;
    };

    // Picks up an already-in-progress run at load time instead of only ever reacting to
    // broadcasts from this point forward — otherwise a different user (or this user in a
    // fresh tab, or after a reload) who opens this page mid-transform never sees the banner
    // at all, since isProcessingLive above only starts true when *this* session's own
    // confirmReprocess/confirmTransition click set it. Mirrors useAiProcessing.ts's
    // isAiProcessing computed (item.processed_at === null || any doc unprocessed) — evaluated
    // once here against the props this page already loaded, since the Echo listener and poll
    // fallback below take over from here.
    const initialChildren = item.children ?? [];
    const hasPendingChildren = initialChildren.some(
        (c) => c.processed_at === null,
    );
    // Whether *this* page is itself a not-yet-populated document that some other document
    // (its parent) is actively generating the content for — e.g. the blank Meeting Notes
    // page the browser lands on right after importing a recording (see
    // MeetingTranscriptController::store()), before ImportMeetingTranscript/ProcessDocumentAI
    // have even run. The status broadcasts describing that work are keyed to the PARENT's
    // id, not this document's own — unlike every other case below, where `item` is the one
    // actively being processed.
    const isSelfPendingChild = item.parent_id != null && item.processed_at === null;

    if (item.processed_at === null || hasPendingChildren) {
        isProcessingLive.value = true;
        aiProgress.value = item.processed_at === null ? 50 : 100;
        trackedChildTotal.value = initialChildren.length;
        completedChildIds.value = new Set(
            initialChildren
                .filter((c) => c.processed_at !== null)
                .map((c) => String(c.id)),
        );
        processingMessage.value = isSelfPendingChild
            ? 'Starting...'
            : batchProgressMessage(null);
        startProcessingPoll();
    }

    onBeforeUnmount(stopProcessingPoll);

    useEcho(
        `project.${project.id}`,
        ['.DocumentProcessingUpdate', '.document.vectorized'],
        (payload: any) => {
            // .document.vectorized: the definitive "this child has fully finished" signal
            // (fired once GenerateDocumentEmbedding commits processed_at) — it never carries a
            // statusMessage, unlike every DocumentProcessingUpdate broadcast (including the
            // ones about this same child mid-embedding), which is how the two are told apart.
            // Membership comes straight off this broadcast's own parent_id, not a pre-known ID
            // list — see the trackedChildTotal comment above.
            if (!payload.statusMessage && payload.document?.id) {
                // Self-pending child (see isSelfPendingChild above, e.g. the blank Meeting
                // Notes page) whose own embedding just finished — the definitive completion
                // signal for that case, same role .document.vectorized already plays for a
                // parent's tracked children just below.
                if (
                    isSelfPendingChild &&
                    String(payload.document.id) === item.id
                ) {
                    clearProcessingState();
                    router.reload();
                    return;
                }

                const isTrackedChild =
                    trackedChildTotal.value > 0 &&
                    String(payload.document.parent_id) === item.id;
                if (isTrackedChild) {
                    completedChildIds.value.add(String(payload.document.id));
                    if (
                        completedChildIds.value.size >= trackedChildTotal.value
                    ) {
                        // A transcript that produced exactly one generated document (the
                        // common case — see DocumentContent.vue's own singleChild/"View
                        // Generated" link, which this mirrors) goes straight there instead of
                        // sitting on the raw transcript once it's done. More than one generated
                        // document has no single obvious destination — same rule that link
                        // already follows — so that case just reloads this page as before.
                        const goToChild =
                            item.type === INTAKE_KEY &&
                            trackedChildTotal.value === 1;
                        const childId = payload.document.id;
                        clearProcessingState();
                        if (goToChild) {
                            router.visit(
                                projectDocumentsRoutes.show({
                                    project: project.id,
                                    document: String(childId),
                                }).url,
                            );
                        } else {
                            router.reload();
                        }
                    }
                }
                return;
            }

            const isThisDoc = payload.document_id === item.id;
            const isTrackedChild =
                trackedChildTotal.value > 0 &&
                payload.document?.parent_id != null &&
                String(payload.document.parent_id) === item.id;
            // Self-pending child watching progress broadcasts about the parent actually doing
            // the work that will populate this page (see isSelfPendingChild above) — just "is
            // this about my one and only parent", no separate id list needed.
            const isMyParent =
                isSelfPendingChild && payload.document_id === item.parent_id;
            if (
                !payload.statusMessage ||
                (!isThisDoc && !isTrackedChild && !isMyParent)
            ) {
                return;
            }

            // A freshly created document (e.g. the page landed on right after the "Import
            // Recording" redirect) starts with isProcessingLive still false — its placeholder
            // processed_at (set by the controller purely to stop the observer firing early)
            // makes the initial pending-detection above think there's nothing in flight yet.
            // The first broadcast that's actually about this page is what tells us otherwise.
            if (!isProcessingLive.value) {
                isProcessingLive.value = true;
                startProcessingPoll();
            }

            const { message, newProgress, isError, isSuccess } =
                parseProcessingStatus(payload);

            if (isError) {
                clearProcessingState();
                toast.error(message);
                return;
            }

            if (isSuccess) {
                aiProgress.value = 100;
            } else if (newProgress > aiProgress.value) {
                aiProgress.value = newProgress;
            }

            if (isThisDoc && payload.newDocumentCount) {
                trackedChildTotal.value = payload.newDocumentCount;
                completedChildIds.value = new Set();
            }

            processingMessage.value = isTrackedChild
                ? (batchProgressMessage(payload.document?.name) ?? message)
                : message;

            // This document's own step reached 100 — if it didn't spawn any new children (a
            // reprocess/transform that produced no new output), that's genuinely the finish
            // line; if it did, wait for their own .document.vectorized above instead of cutting
            // off their progress the instant this fires, same gap useAiProcessing.ts avoids on
            // the project tree page.
            if (isSuccess && isThisDoc && trackedChildTotal.value === 0) {
                clearProcessingState();
                router.reload();
            }
        },
        [project.id],
        'private',
    );

    const { reprocessableTypes } = useWorkflow();

    // Mirrors the same "does this document have anything to reprocess" rule used by the
    // tree/detail-sheet Reprocess button — a locked document only has something to
    // reprocess if its locked protocol still defines a further step for its own type.
    const canOfferReprocess = () => {
        const isLocked = !!item.locked_project_type_id;

        return (
            reprocessableTypes.value.has(item.type) ||
            (isLocked && !!item.locked_next_workflow_step_exists)
        );
    };

    // Tab detection logic
    const getCurrentTab = () => {
        const params = new URLSearchParams(window.location.search);
        return params.get('tab') || 'hierarchy';
    };

    /**
     * Helper to ensure metadata is an object before form initialization.
     * Replicates the safeJsonParse logic from useDocumentActions.
     */
    const getInitialMetadata = (data: any): DocumentMetadata => {
        if (!data) return { criteria: [] };
        if (typeof data !== 'string') return data as DocumentMetadata;
        try {
            return JSON.parse(data);
        } catch {
            return { criteria: [] };
        }
    };

    const form = useForm<DocumentFields & { tab?: string }>({
        id: String(item.id),
        name: item.name,
        content: item.content,
        type: item.type,
        assignee_id: item.assignee_id,
        project_id: project.id,
        metadata: getInitialMetadata(item.metadata),
        priority: item.priority,
        task_status: item.task_status,
        due_at: item.due_at,
        start_at: item.start_at,
        custom_prompt: item.custom_prompt ?? null,
    });

    const syncSidebarFields = (newItem: ExtendedDocument) => {
        // Self-correction for a missed broadcast: whenever Inertia hands us a freshly
        // replaced `item` (from the poll fallback above, or any other reload/navigation)
        // and it turns out processing has actually finished, stop showing "processing" even
        // though the completion event itself never arrived. newItem.processed_at only
        // reflects *this* document's own step finishing, though — a transform that spawned
        // children can easily still have some of them mid-embedding at that point (their own
        // processed_at lives on the child rows, invisible here), so this only fires once any
        // tracked batch has also fully drained; otherwise it would cut the "Finalizing N of M"
        // progress short the next time this poll fires after the source step completes.
        if (
            isProcessingLive.value &&
            newItem.processed_at &&
            (trackedChildTotal.value === 0 ||
                completedChildIds.value.size >= trackedChildTotal.value)
        ) {
            clearProcessingState();
        }

        if (!isEditing.value) {
            form.priority = newItem.priority;
            form.task_status = newItem.task_status;
            form.due_at = newItem.due_at;
            form.start_at = newItem.start_at;
            form.assignee_id = newItem.assignee_id;
            form.defaults({
                ...form.data(),
                priority: newItem.priority,
                task_status: newItem.task_status,
                due_at: newItem.due_at,
                start_at: newItem.start_at,
                assignee_id: newItem.assignee_id,
            });
        }
    };

    const toggleEdit = () => {
        isEditing.value = !isEditing.value;
        if (!isEditing.value) form.reset();
    };

    const handleFormSubmit = (onSuccessCallback?: () => void) => {
        form.tab = getCurrentTab();
        const url = projectDocumentsRoutes.update({
            project: project.id,
            document: item.id,
        }).url;

        form.put(url, {
            preserveScroll: true,
            onSuccess: async () => {
                isEditing.value = false;
                await nextTick();
                toast.success('Document updated successfully');
                if (canOfferReprocess()) isReprocessPromptOpen.value = true;
                if (onSuccessCallback) onSuccessCallback();
            },
            onError: () => toast.error('Failed to update document'),
        });
    };

    const confirmReprocess = async (
        oneOffInstructions: string | null = null,
    ) => {
        isReprocessing.value = true;

        // Flip the live status synchronously — before the network round trip — so the
        // progress bar/header appear the instant the button is pressed rather than
        // waiting on the server's response.
        isProcessingLive.value = true;
        processingMessage.value = 'Starting...';
        aiProgress.value = 5;
        startProcessingPoll();

        const url = projectDocumentsRoutes.reprocess.url({
            project: project.id,
            document: item.id,
        });

        // The reprocess endpoint returns plain JSON (it's also called via axios from the
        // tree/kanban views), not an Inertia response, so it can't go through router.post.
        // Once dispatched, the live status above takes over until the job's own
        // success/error broadcast arrives — no immediate reload here.
        try {
            const response = await axios.post(url, {
                one_off_instructions: oneOffInstructions,
            });
            if (redirectIfLoggedOut(response)) return;
        } catch (error) {
            clearProcessingState();

            if (redirectIfSessionExpiredError(error)) return;

            toast.error('Failed to start reprocessing');
        } finally {
            isReprocessing.value = false;
            isReprocessPromptOpen.value = false;
        }
    };

    const isTransitioning = ref(false);

    // Mirrors confirmReprocess above — same live-status/poll/Echo machinery (that listener is
    // generic to any AI job on this document, keyed only on document_id), just posting to the
    // transition endpoint instead of reprocess. See TraceabilityRow.vue's Transform button for
    // the tree-view equivalent of this same flow.
    const confirmTransition = async (payload: {
        toKey?: string;
        aiTemplateId: number;
        singleOutput?: boolean;
        projectTypeId?: string;
    }) => {
        isTransitioning.value = true;

        isProcessingLive.value = true;
        processingMessage.value = 'Starting...';
        aiProgress.value = 5;
        startProcessingPoll();

        const url = projectDocumentsRoutes.transition.url({
            project: project.id,
            document: item.id,
        });

        try {
            const response = await axios.post(url, {
                to_key: payload.toKey,
                ai_template_id: payload.aiTemplateId,
                single_output: payload.singleOutput,
                project_type_id: payload.projectTypeId,
            });
            if (redirectIfLoggedOut(response)) return;
        } catch (error) {
            clearProcessingState();

            if (redirectIfSessionExpiredError(error)) return;

            toast.error('Failed to start transition');
        } finally {
            isTransitioning.value = false;
        }
    };

    const confirmDeletion = () => {
        isDeleting.value = true;
        const url = projectDocumentsRoutes.destroy({
            project: project.id,
            document: item.id,
        }).url;

        router.delete(url, {
            onSuccess: () => {
                toast.success('Document deleted');
            },
            onFinish: () => {
                isDeleting.value = false;
                isDeleteModalOpen.value = false;
            },
        });
    };

    return {
        form,
        isEditing,
        isDeleting,
        isDeleteModalOpen,
        isReprocessPromptOpen,
        isReprocessing,
        isProcessingLive,
        processingMessage,
        aiProgress,
        toggleEdit,
        handleFormSubmit,
        confirmDeletion,
        confirmReprocess,
        isTransitioning,
        confirmTransition,
        getCurrentTab,
        syncSidebarFields,
    };
}

import { ref, computed, watch, onBeforeUnmount, type Ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import axios from 'axios';
import { globalAiState } from '@/state';

// How often to re-check server truth while something appears to be processing — a safety net
// for a missed .DocumentProcessingUpdate broadcast. This covers a case connection-state
// monitoring (see useEchoWatchdog) can't: the socket itself never drops, but one specific
// message is lost, so nothing ever tells the client to stop waiting. Cheap in the common case,
// since the live broadcast almost always resolves isAiProcessing well before the first tick.
const PROCESSING_POLL_INTERVAL_MS = 15000;

export function useAiProcessing(
    projectId: string,
    allDocs: Ref<ExtendedDocument[]>,
    targetBeingCreated: Ref<string | number | null>,
    onDocumentUpdated?: (doc: ExtendedDocument) => void,
    onSuccess?: (message: string) => void,
    onError?: (message: string) => void,
    onDocumentsRemoved?: (ids: Array<string | number>) => void,
    reloadPropsOnNewDocuments?: string[]
) {
    const aiStatusMessage = ref<string>('');
    const aiProgress = ref<number>(0);
    let creepInterval: ReturnType<typeof setInterval> | null = null;

    // --- 1. CLEANUP LOGIC ---
    const stopCreep = () => {
        if (creepInterval) {
            clearInterval(creepInterval);
            creepInterval = null;
        }
    };

    onBeforeUnmount(stopCreep);

    // --- 2. DERIVED STATE ---
    // AI is processing if there's an active target being created OR any document has null processed_at
    const isAiProcessing = computed(() => {
    const isTargeting = !!targetBeingCreated.value;
    const pendingDoc = allDocs.value.find(d => d.processed_at === null);

    return isTargeting || !!pendingDoc;
});

    // Two related self-corrections for isAiProcessing getting stuck true with nothing left to
    // resolve it, layered on top of the normal live-broadcast path:
    //
    // 1. Browser back/forward navigation restores Inertia's cached history snapshot entirely
    //    client-side (no server round-trip) — see handlePopstateEvent in @inertiajs/core. If a
    //    reprocess finished while this page was navigated away from, the cached snapshot still
    //    shows the pre-completion processed_at: null, so isAiProcessing comes back stuck true
    //    on remount even though nothing is actually running anymore.
    // 2. A tab left open for the job's whole duration that simply never received the one
    //    .DocumentProcessingUpdate broadcast telling it to stop — no remount ever happens here,
    //    so a one-off mount-time check alone can't catch it.
    //
    // Both are handled the same way: reconcile with the server, once immediately if
    // isAiProcessing is already true at mount (covers case 1) and then again every
    // PROCESSING_POLL_INTERVAL_MS for as long as it stays true (covers case 2). A flip to
    // true *after* mount — e.g. the user just pressed a reprocess button — skips that
    // immediate reconcile: firing a reload in the same tick as the action that's about to
    // set processed_at server-side is a race, and losing it clobbers the fresh optimistic
    // state with the not-yet-updated value, flashing the progress bar off again. Since we
    // already know why we're processing in that case, the first periodic tick is enough.
    let processingPollTimer: ReturnType<typeof setInterval> | null = null;
    let reconcileCount = 0;
    let hasMounted = false;

    const stopProcessingPoll = () => {
        if (processingPollTimer) {
            clearInterval(processingPollTimer);
            processingPollTimer = null;
        }
        reconcileCount = 0;
    };

    const reconcileWithServer = () => {
        reconcileCount++;
        const pendingBefore = allDocs.value.filter(d => d.processed_at === null).map(d => d.id);

        router.reload({
            ...(reloadPropsOnNewDocuments?.length ? { only: reloadPropsOnNewDocuments } : {}),
            onSuccess: () => {
                // Only a poll tick after the first (the immediate, mount-time-equivalent check)
                // means a full interval passed with the live broadcast never arriving — that's
                // the genuinely diagnostic-worthy case, not just a normal fresh load or a
                // same-tick cache correction.
                if (reconcileCount > 1 && !isAiProcessing.value && pendingBefore.length) {
                    axios.post('/log-stale-processing', {
                        project_id: projectId,
                        document_ids: pendingBefore,
                        stuck_for_ms: reconcileCount * PROCESSING_POLL_INTERVAL_MS,
                    }).catch(() => {
                        // Best-effort diagnostic logging — never block or surface an error over this.
                    });
                }
            },
        });
    };

    watch(isAiProcessing, (isProcessing) => {
        if (isProcessing) {
            if (!processingPollTimer) {
                if (!hasMounted) {
                    reconcileWithServer();
                }
                processingPollTimer = setInterval(reconcileWithServer, PROCESSING_POLL_INTERVAL_MS);
            }
        } else {
            stopProcessingPoll();
        }
        hasMounted = true;
    }, { immediate: true });

    onBeforeUnmount(stopProcessingPoll);

    // Sync Global AI State
    watch(isAiProcessing, (newVal) => {
        globalAiState.value.isProcessing = newVal;
        if (!newVal) {
            aiProgress.value = 0;
            aiStatusMessage.value = '';
            stopCreep();
        }
    }, { immediate: true });

    // --- 3. PROGRESS ANIMATION (The "Creep") ---
    watch(aiProgress, (newVal) => {
        stopCreep();
        // Only creep if we are in active progress but haven't reached the end
        if (newVal > 0 && newVal < 90) {
            creepInterval = setInterval(() => {
                if (aiProgress.value < newVal + 15 && aiProgress.value < 95) {
                    aiProgress.value += 0.5;
                }
            }, 1000);
        }
    });

    // --- 4. REAL-TIME LISTENER ---
    // Listens for both specific document updates and general processing progress
    useEcho(`project.${projectId}`, ['.document.vectorized', '.DocumentProcessingUpdate'], (payload: any) => {

        // 1. HANDLE STATUS UPDATES
        if (payload.statusMessage) {
            const message = String(payload.statusMessage);
            const msg = message.toLowerCase();
            const newProgress = Number(payload.progress || 0);

            const isError = msg.includes('error') || msg.includes('failed');
            const isSuccess = (msg.includes('success') || newProgress === 100) && !isError;

            if (isSuccess) {
                aiProgress.value = 100;
                onSuccess?.(message);
                // NOTICE: We do NOT clear targetBeingCreated or doc status here
            } else if (isError) {
                aiProgress.value = 0;
                targetBeingCreated.value = null;
                aiStatusMessage.value = '';
                onError?.(message);
            }

            if (!isSuccess && newProgress > aiProgress.value) {
                aiProgress.value = newProgress;
            }

            // Keep the message visible
            aiStatusMessage.value = message;
        }

        // 2. HANDLE DATA ARRIVAL (The Finish Line)
        if (payload.document && onDocumentUpdated) {
            // This update will satisfy the 'processed_at === null' check
            // and trigger the computed isAiProcessing to turn false naturally.
            onDocumentUpdated({
                ...payload.document,
                currentStatus: null
            });

            // Reset global tracking only after data is rendered
            targetBeingCreated.value = null;
        }

        // 3. HANDLE NEWLY CREATED CHILDREN (e.g. from reprocessing)
        // Only IDs are broadcast (full documents can exceed the broadcaster's
        // payload limit), so pull the new records in via a partial reload.
        if (Array.isArray(payload.newDocumentIds) && payload.newDocumentIds.length) {
            router.reload(reloadPropsOnNewDocuments?.length ? { only: reloadPropsOnNewDocuments } : {});
        }

        // 4. HANDLE STALE CHILDREN REMOVED BY REPROCESSING
        if (Array.isArray(payload.deletedDocumentIds) && payload.deletedDocumentIds.length && onDocumentsRemoved) {
            onDocumentsRemoved(payload.deletedDocumentIds);
        }
    }, [projectId], 'private');

    return {
        aiStatusMessage,
        aiProgress,
        isAiProcessing
    };
}

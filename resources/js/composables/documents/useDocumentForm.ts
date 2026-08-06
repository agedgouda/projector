import { ref, nextTick, onBeforeUnmount, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { toast } from 'vue-sonner';
import axios from 'axios';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import { useWorkflow } from '@/composables/useWorkflow';
import { redirectIfLoggedOut, redirectIfSessionExpiredError } from '@/lib/sessionExpiry';

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
            router.reload({ only: ['item'], preserveScroll: true, preserveState: true });
        }, PROCESSING_POLL_INTERVAL_MS);
    };

    const clearProcessingState = () => {
        isProcessingLive.value = false;
        processingMessage.value = null;
        aiProgress.value = 0;
        stopCreep();
        stopProcessingPoll();
    };

    onBeforeUnmount(stopProcessingPoll);

    useEcho(
        `project.${project.id}`,
        ['.DocumentProcessingUpdate'],
        (payload: any) => {
            if (payload.document_id !== item.id || !payload.statusMessage) return;

            const message = String(payload.statusMessage);
            const msg = message.toLowerCase();
            const newProgress = Number(payload.progress || 0);
            const isError = msg.includes('error') || msg.includes('failed');
            const isSuccess = (msg.includes('success') || newProgress === 100) && !isError;

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

            processingMessage.value = message;

            if (isSuccess) {
                clearProcessingState();
                router.reload();
            }
        },
        [project.id],
        'private'
    );

    const { reprocessableTypes } = useWorkflow();

    // Mirrors the same "does this document have anything to reprocess" rule used by the
    // tree/detail-sheet Reprocess button — a locked document only has something to
    // reprocess if its locked protocol still defines a further step for its own type.
    const canOfferReprocess = () => {
        const isLocked = !!item.locked_project_type_id;

        return reprocessableTypes.value.has(item.type) || (isLocked && !!item.locked_next_workflow_step_exists);
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
        custom_prompt: item.custom_prompt ?? null,
    });

    const syncSidebarFields = (newItem: ExtendedDocument) => {
        // Self-correction for a missed broadcast: whenever Inertia hands us a freshly
        // replaced `item` (from the poll fallback above, or any other reload/navigation)
        // and it turns out processing has actually finished, stop showing "processing" even
        // though the completion event itself never arrived.
        if (isProcessingLive.value && newItem.processed_at) {
            clearProcessingState();
        }

        if (!isEditing.value) {
            form.priority = newItem.priority;
            form.task_status = newItem.task_status;
            form.due_at = newItem.due_at;
            form.assignee_id = newItem.assignee_id;
            form.defaults({
                ...form.data(),
                priority: newItem.priority,
                task_status: newItem.task_status,
                due_at: newItem.due_at,
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
            document: item.id
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

    const confirmReprocess = async (oneOffInstructions: string | null = null) => {
        isReprocessing.value = true;
        const url = projectDocumentsRoutes.reprocess.url({ project: project.id, document: item.id });

        // The reprocess endpoint returns plain JSON (it's also called via axios from the
        // tree/kanban views), not an Inertia response, so it can't go through router.post.
        // Once dispatched, the live status above takes over until the job's own
        // success/error broadcast arrives — no immediate reload here.
        try {
            const response = await axios.post(url, { one_off_instructions: oneOffInstructions });
            if (redirectIfLoggedOut(response)) return;

            isProcessingLive.value = true;
            processingMessage.value = 'Starting...';
            aiProgress.value = 5;
            startProcessingPoll();
        } catch (error) {
            if (redirectIfSessionExpiredError(error)) return;

            toast.error('Failed to start reprocessing');
        } finally {
            isReprocessing.value = false;
            isReprocessPromptOpen.value = false;
        }
    };

    const confirmDeletion = () => {
        isDeleting.value = true;
        const url = projectDocumentsRoutes.destroy({
            project: project.id,
            document: item.id
        }).url;

        router.delete(url, {
            onSuccess: () => {
                toast.success('Document deleted');
            },
            onFinish: () => {
                isDeleting.value = false;
                isDeleteModalOpen.value = false;
            }
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
        getCurrentTab,
        syncSidebarFields,
    };
}

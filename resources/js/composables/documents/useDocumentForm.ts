import { ref, nextTick } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { toast } from 'vue-sonner';
import axios from 'axios';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import { useWorkflow } from '@/composables/useWorkflow';

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

    useEcho(
        `project.${project.id}`,
        ['.DocumentProcessingUpdate'],
        (payload: any) => {
            if (payload.document_id !== item.id || !payload.statusMessage) return;

            const message = String(payload.statusMessage);
            const msg = message.toLowerCase();
            const isError = msg.includes('error') || msg.includes('failed');
            const isSuccess = (msg.includes('success') || Number(payload.progress) === 100) && !isError;

            if (isError) {
                isProcessingLive.value = false;
                processingMessage.value = null;
                toast.error(message);
                return;
            }

            processingMessage.value = message;

            if (isSuccess) {
                isProcessingLive.value = false;
                processingMessage.value = null;
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
    });

    const syncSidebarFields = (newItem: ExtendedDocument) => {
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

    const confirmReprocess = async () => {
        isReprocessing.value = true;
        const url = projectDocumentsRoutes.reprocess.url({ project: project.id, document: item.id });

        // The reprocess endpoint returns plain JSON (it's also called via axios from the
        // tree/kanban views), not an Inertia response, so it can't go through router.post.
        // Once dispatched, the live status above takes over until the job's own
        // success/error broadcast arrives — no immediate reload here.
        try {
            await axios.post(url);
            isProcessingLive.value = true;
            processingMessage.value = 'Starting...';
        } catch {
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
        toggleEdit,
        handleFormSubmit,
        confirmDeletion,
        confirmReprocess,
        getCurrentTab,
        syncSidebarFields,
    };
}

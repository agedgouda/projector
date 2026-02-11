import { ref, nextTick } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import projectDocumentsRoutes from '@/routes/projects/documents/index';

export function useDocumentForm(project: Project, item: ExtendedDocument) {
    const isEditing = ref(false);
    const isDeleteModalOpen = ref(false);
    const isDeleting = ref(false);

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
                if (onSuccessCallback) onSuccessCallback();
            },
            onError: () => toast.error('Failed to update document'),
        });
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
        toggleEdit,
        handleFormSubmit,
        confirmDeletion,
        getCurrentTab
    };
}

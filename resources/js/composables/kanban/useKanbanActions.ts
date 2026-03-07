import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { Ref } from 'vue';
import projectRoutes from '@/routes/projects';
import type { KanbanProps } from './useKanbanBoard';

export function useKanbanActions(
    props: KanbanProps,
    applyLocalUpdate: (id: string | number, data: Record<string, any>) => void,
    documentsById: Ref<Record<string | number, ProjectDocument>>
) {

    /**
     * Resolves the project ID that owns a given document.
     * Falls back to currentProject if the document isn't found in local state.
     */
    const projectIdForDoc = (documentId: string | number): string | undefined => {
        const doc = documentsById.value[documentId];
        return doc?.project_id ?? props.currentProject?.id;
    };

    /**
     * Updates a document attribute with Optimistic UI support
     */
    const updateAttribute = (
        documentId: string | number,
        data: Record<string, any>,
        successMessage?: string
    ) => {
        const projectId = projectIdForDoc(documentId);
        if (!projectId) return;

        const docIdStr = String(documentId);
        const route = projectRoutes.documents.update({
            project: projectId,
            document: docIdStr
        });

        router.patch(route.url, data, {
            preserveScroll: true,
            preserveState: true,
            onBefore: () => {
                // 1. Trigger the visual move immediately
                applyLocalUpdate(documentId, data);
            },
            onSuccess: () => {
                if (successMessage) toast.success(successMessage);
            },
            onError: () => {
                // 2. Rollback happens automatically!
                // When Inertia gets an error, it re-renders the page with
                // the "old" props. Our watcher in useKanbanState will see
                // the old props and reset localKanbanData automatically.
                toast.error('Failed to save changes. Reverting...');
            }
        });
    };

    const handleCreateNew = (projectId: string) => {
        const route = projectRoutes.documents.create({ project: projectId });
        router.visit(route.url);
    };

    const switchProject = (projectId: string | number) => {
        router.get('/dashboard', { project: projectId }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return {
        updateAttribute,
        handleCreateNew,
        switchProject
    };
}

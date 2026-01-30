import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import projectRoutes from '@/routes/projects';
import type { KanbanProps } from './useKanbanBoard';

export function useKanbanActions(
    props: KanbanProps,
    applyLocalUpdate: (id: string | number, data: Record<string, any>) => void
) {

    /**
     * Updates a document attribute with Optimistic UI support
     */
    const updateAttribute = (
        documentId: string | number,
        data: Record<string, any>,
        successMessage?: string
    ) => {

        if (!props.currentProject) return;

        const docIdStr = String(documentId);
        const route = projectRoutes.documents.update({
            project: props.currentProject.id,
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

    const handleCreateNew = (typeKey: string) => {
        if (!props.currentProject) return;
        const route = projectRoutes.documents.create({ project: props.currentProject.id });
        router.visit(route.url, { data: { type: typeKey } });
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

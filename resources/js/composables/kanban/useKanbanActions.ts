import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import projectRoutes from '@/routes/projects';
import type { KanbanProps } from './useKanbanBoard';

export function useKanbanActions(props: KanbanProps) {

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
                // This is where you would update a LOCAL ref if you had one.
                // Since we are using Inertia props, we rely on preserveState
                // and the server's fast response.
            },
            onSuccess: () => {
                if (successMessage) toast.success(successMessage);
            },
            onError: () => {
                // If the server rejects the change, Inertia automatically
                // re-renders the component with the "Old" props from the session,
                // effectively performing the rollback for us.
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

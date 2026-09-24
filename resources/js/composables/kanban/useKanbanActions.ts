import { saveDocument } from '@/lib/saveDocument';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import type { Ref } from 'vue';
import type { KanbanProps } from './useKanbanBoard';

export function useKanbanActions(
    props: KanbanProps,
    applyLocalUpdate: (id: string | number, data: Record<string, any>) => void,
    documentsById: Ref<Record<string | number, ProjectDocument>>,
    openCreateSheet: (projectId: string) => void,
) {
    /**
     * Resolves the project ID that owns a given document.
     * Falls back to currentProject if the document isn't found in local state.
     */
    const projectIdForDoc = (
        documentId: string | number,
    ): string | undefined => {
        const doc = documentsById.value[documentId];
        return doc?.project_id ?? props.currentProject?.id;
    };

    /**
     * Saves changes to a document (see saveDocument()) and keeps the board's copy in step.
     */
    const updateAttribute = (
        documentId: string | number,
        data: Record<string, any>,
        successMessage?: string,
    ): Promise<boolean> => {
        const projectId = projectIdForDoc(documentId);
        const current = documentsById.value[documentId];
        if (!projectId || !current) return Promise.resolve(false);

        return saveDocument(projectId, documentId, data, {
            current,
            apply: (values) => applyLocalUpdate(documentId, values),
            successMessage,
        });
    };

    /**
     * Sets the complete list of tags on a task — sync semantics (send the full desired set, not a
     * single add/remove).
     */
    const updateTags = (
        documentId: string | number,
        categories: CategoryDef[],
    ) => {
        void updateAttribute(documentId, {
            category_ids: categories.map((c) => c.id),
        });
    };

    // Comments aren't part of a document's usual attribute set, so there's nothing to send
    // an optimistic value for — just re-fetch the fresh list and patch it in like any other
    // local update, after CommentSection.vue's Inertia form.post/delete has landed.
    const refreshComments = async (documentId: string | number) => {
        const response = await axios.get('/comments', {
            params: { type: 'document', id: documentId },
        });
        applyLocalUpdate(documentId, { comments: response.data.comments });
    };

    // The empty-column "+" button — projectId is actually the row's key, which (in every
    // current call site) is the owning project's id. Opens the shared DocumentDetailSheet in
    // create mode instead of navigating to the full-page form (see Documents/Create.vue).
    const handleCreateNew = (projectId: string) => {
        openCreateSheet(projectId);
    };

    const switchProject = (projectId: string | number) => {
        router.get(
            '/dashboard',
            { project: projectId },
            {
                preserveState: true,
                preserveScroll: true,
            },
        );
    };

    return {
        updateAttribute,
        updateTags,
        refreshComments,
        handleCreateNew,
        switchProject,
    };
}

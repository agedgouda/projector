import { show } from '@/routes/projects/documents';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { KanbanProps } from './useKanbanBoard';

export function useKanbanState(props: KanbanProps) {
    const selectedDocumentId = ref<string | number | null>(null);
    const isSheetOpen = ref(false);

    // 1. Create a local copy of the kanban data for optimistic updates
    const deepCopyKanbanData = (data: Record<string, ProjectDocument[]>) =>
        Object.fromEntries(Object.entries(data).map(([k, v]) => [k, [...v]]));

    const localKanbanData = ref<Record<string, ProjectDocument[]>>(
        deepCopyKanbanData(props.kanbanData),
    );

    // 2. Keep local state in sync when server-side props change (e.g., after a real refresh)
    watch(
        () => props.kanbanData,
        (newData) => {
            localKanbanData.value = deepCopyKanbanData(newData);
        },
        { deep: true },
    );

    /**
     * Optimistic Update: Manually patch the local state
     */
    const applyLocalUpdate = (
        documentId: string | number,
        data: Record<string, any>,
    ) => {
        const docIdStr = String(documentId);
        let found = false;

        // 1. Try to update existing document
        Object.keys(localKanbanData.value).forEach((rowKey) => {
            const index = localKanbanData.value[rowKey].findIndex(
                (d) => String(d.id) === docIdStr,
            );

            if (index !== -1) {
                found = true;
                const existingDoc = localKanbanData.value[rowKey][index];

                // Handle status/column movement
                if (
                    data.task_status &&
                    data.task_status !== existingDoc.task_status
                ) {
                    // Remove from old location
                    localKanbanData.value[rowKey].splice(index, 1);

                    // Document stays in the same project row; only the status column changes
                    localKanbanData.value[rowKey].push({
                        ...existingDoc,
                        ...data,
                    });
                } else {
                    // Simple field update
                    localKanbanData.value[rowKey][index] = {
                        ...existingDoc,
                        ...data,
                    };
                }
            }
        });

        // 2. NEW DOCUMENT CASE: If AI created a doc that isn't on the board yet
        if (!found) {
            // Rows are keyed by project ID
            const rowKey = data.project_id;
            if (rowKey) {
                if (!localKanbanData.value[rowKey])
                    localKanbanData.value[rowKey] = [];

                // Default to 'todo' if no status is provided by the AI yet
                const newDoc = {
                    id: documentId,
                    task_status: 'todo',
                    ...data,
                } as ProjectDocument;

                localKanbanData.value[rowKey].push(newDoc);
            }
        }
    };

    const documentsById = computed(() => {
        const map: Record<string | number, ProjectDocument> = {};
        // 3. Always use localKanbanData for the UI to see the optimistic changes
        Object.values(localKanbanData.value).forEach((column) => {
            column.forEach((doc) => {
                map[doc.id] = doc;
            });
        });
        return map;
    });

    const selectedDocument = computed(() =>
        selectedDocumentId.value
            ? documentsById.value[selectedDocumentId.value]
            : null,
    );

    const openDetail = (doc: ProjectDocument) => {
        const projectId = doc.project_id ?? props.currentProject?.id;
        if (!projectId) return;

        const fromUrl = new URL(window.location.href);
        if (props.currentProject && !fromUrl.searchParams.has('tab')) {
            fromUrl.searchParams.set('tab', 'tasks');
        }

        const url = show.url(
            { project: String(projectId), document: String(doc.id) },
            { query: { from: fromUrl.toString() } },
        );
        router.visit(url);
    };

    /**
     * Local Update: Remove every descendant of rootId that no longer exists on the
     * server, e.g. stale children replaced by reprocessing. Only the root id is
     * given (not the removed IDs themselves — a large batch could otherwise exceed
     * the broadcaster's payload limit), so descendants are found by walking
     * parent_id across all row buckets, mirroring the parent_id foreign key's own
     * ON DELETE CASCADE on the backend.
     */
    const removeLocalDocuments = (rootId: string | number) => {
        const rootKey = String(rootId);
        const allDocs = Object.values(localKanbanData.value).flat();

        const toRemove = new Set<string>();
        const queue = allDocs
            .filter((d) => String(d.parent_id) === rootKey)
            .map((d) => String(d.id));

        while (queue.length) {
            const id = queue.shift()!;
            if (toRemove.has(id)) continue;
            toRemove.add(id);
            allDocs.forEach((d) => {
                if (String(d.parent_id) === id && !toRemove.has(String(d.id))) {
                    queue.push(String(d.id));
                }
            });
        }

        Object.keys(localKanbanData.value).forEach((rowKey) => {
            localKanbanData.value[rowKey] = localKanbanData.value[
                rowKey
            ].filter((d) => !toRemove.has(String(d.id)));
        });
    };

    return {
        selectedDocumentId,
        selectedDocument,
        isSheetOpen,
        localKanbanData, // Export this so useKanbanQueries can use it
        documentsById,
        applyLocalUpdate,
        removeLocalDocuments,
        openDetail,
    };
}

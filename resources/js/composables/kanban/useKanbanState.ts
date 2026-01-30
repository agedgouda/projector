import { ref, computed, watch } from 'vue';
import type { KanbanProps } from './useKanbanBoard';

export function useKanbanState(props: KanbanProps) {
    const selectedDocumentId = ref<string | number | null>(null);
    const isSheetOpen = ref(false);

    // 1. Create a local copy of the kanban data for optimistic updates
    const localKanbanData = ref<Record<string, ProjectDocument[]>>({ ...props.kanbanData });

    // 2. Keep local state in sync when server-side props change (e.g., after a real refresh)
    watch(() => props.kanbanData, (newData) => {
        localKanbanData.value = { ...newData };
    }, { deep: true });

    /**
     * Optimistic Update: Manually patch the local state
     */
    const applyLocalUpdate = (documentId: string | number, data: Record<string, any>) => {
        const docIdStr = String(documentId);
        let found = false;

        // 1. Try to update existing document
        Object.keys(localKanbanData.value).forEach(rowKey => {
            const index = localKanbanData.value[rowKey].findIndex(d => String(d.id) === docIdStr);

            if (index !== -1) {
                found = true;
                const existingDoc = localKanbanData.value[rowKey][index];

                // Handle status/column movement
                if (data.task_status && data.task_status !== existingDoc.task_status) {
                    // Remove from old location
                    localKanbanData.value[rowKey].splice(index, 1);

                    // Add to new status (on the same row/type)
                    const targetRow = data.type || existingDoc.type || rowKey;
                    if (!localKanbanData.value[targetRow]) localKanbanData.value[targetRow] = [];
                    localKanbanData.value[targetRow].push({ ...existingDoc, ...data });
                } else {
                    // Simple field update
                    localKanbanData.value[rowKey][index] = { ...existingDoc, ...data };
                }
            }
        });

        // 2. NEW DOCUMENT CASE: If AI created a doc that isn't on the board yet
        if (!found) {
            // We use the 'type' field from the incoming data to decide which row it goes in
            const rowKey = data.type;
            if (rowKey) {
                if (!localKanbanData.value[rowKey]) localKanbanData.value[rowKey] = [];

                // Default to 'todo' if no status is provided by the AI yet
                const newDoc = {
                    id: documentId,
                    task_status: 'todo',
                    ...data
                } as ProjectDocument;

                localKanbanData.value[rowKey].push(newDoc);
            }
        }
};

    const documentsById = computed(() => {
        const map: Record<string | number, ProjectDocument> = {};
        // 3. Always use localKanbanData for the UI to see the optimistic changes
        Object.values(localKanbanData.value).forEach(column => {
            column.forEach(doc => {
                map[doc.id] = doc;
            });
        });
        return map;
    });

    const selectedDocument = computed(() =>
        selectedDocumentId.value ? documentsById.value[selectedDocumentId.value] : null
    );

    const openDetail = (doc: ProjectDocument) => {
        selectedDocumentId.value = doc.id;
        isSheetOpen.value = true;
    };

    return {
        selectedDocumentId,
        selectedDocument,
        isSheetOpen,
        localKanbanData, // Export this so useKanbanQueries can use it
        documentsById,
        applyLocalUpdate,
        openDetail
    };
}

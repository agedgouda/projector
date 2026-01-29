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

        // If we're moving status, we need to move the item between arrays
        if (data.task_status) {
            const oldStatus = Object.keys(localKanbanData.value).find(status =>
                localKanbanData.value[status].some(d => String(d.id) === docIdStr)
            );

            if (oldStatus && oldStatus !== data.task_status) {
                const index = localKanbanData.value[oldStatus].findIndex(d => String(d.id) === docIdStr);
                const [item] = localKanbanData.value[oldStatus].splice(index, 1);

                const newStatus = data.task_status as string;
                if (!localKanbanData.value[newStatus]) localKanbanData.value[newStatus] = [];

                // Push the updated item to the new column
                localKanbanData.value[newStatus].push({ ...item, ...data });
            }
        } else {
            // Just update fields (like name or priority) without moving columns
            Object.keys(localKanbanData.value).forEach(status => {
                const doc = localKanbanData.value[status].find(d => String(d.id) === docIdStr);
                if (doc) Object.assign(doc, data);
            });
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

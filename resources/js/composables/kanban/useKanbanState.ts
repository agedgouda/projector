import { computed, ref, watch } from 'vue';
import axios from 'axios';
import type { KanbanProps } from './useKanbanBoard';

export function useKanbanState(props: KanbanProps) {
    const selectedDocumentId = ref<string | number | null>(null);
    const isSheetOpen = ref(false);

    // Every host page renders the view/edit sheet and the create sheet as a v-if/v-else-if
    // pair keyed on selectedDocument/isCreateSheetOpen (see Projects/Show.vue,
    // Dashboard/Index.vue), so the create sheet can only ever appear while selectedDocumentId
    // is unset. Without this, closing the view/edit sheet (Escape, backdrop click, the X
    // button, or any of the several call sites that set isSheetOpen.value = false directly)
    // left selectedDocumentId — and so the selectedDocument computed below — permanently
    // truthy, silently blocking "New Task"/"New Document" for the rest of that page's life.
    watch(isSheetOpen, (open) => {
        if (!open) {
            selectedDocumentId.value = null;
        }
    });

    // Drives DocumentDetailSheet's create mode (see useKanbanActions.ts's handleCreateNew,
    // triggered by the empty-column "+" button, and each host page's own top toolbar "New
    // Task" button) — a separate open flag/target from the view/edit sheet above so the two
    // never fight over which document (existing vs. none yet) the one shared sheet instance
    // should be showing.
    const isCreateSheetOpen = ref(false);
    const createSheetProjectId = ref<string | null>(null);

    const openCreateSheet = (projectId: string) => {
        // Should be unreachable now that the watch above clears selectedDocumentId whenever
        // isSheetOpen goes false, since openDetail() is the only thing that sets it. If this
        // still fires, the create sheet is silently blocked again the same way it used to be —
        // log it so a recurrence (or a different path into the same blocked state) shows up
        // instead of just looking like "New Task did nothing" again.
        if (selectedDocumentId.value !== null) {
            const payload = {
                selected_document_id: selectedDocumentId.value,
                project_id: projectId,
                page_url: window.location.href,
            };
            console.warn(
                '[useKanbanState] openCreateSheet blocked by stale selectedDocumentId',
                payload,
            );
            void axios
                .post('/client-logs/create-sheet-blocked', payload)
                .catch(() => {});
        }

        createSheetProjectId.value = projectId;
        isCreateSheetOpen.value = true;
    };

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
        selectedDocumentId.value = doc.id;
        isSheetOpen.value = true;
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
        isCreateSheetOpen,
        createSheetProjectId,
        localKanbanData, // Export this so useKanbanQueries can use it
        documentsById,
        applyLocalUpdate,
        removeLocalDocuments,
        openDetail,
        openCreateSheet,
    };
}

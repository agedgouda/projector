import { ref, computed, Ref } from 'vue';
import { useDocumentTree } from '@/composables/useDocumentTree';

export function useProjectState(
    initialDocs: ProjectDocument[] = [],
    schema: Ref<DocumentSchemaItem[]> // Received from DocumentManager
) {
    // 1. CENTRALIZED STATE
    // We initialize the Map with the full document list (including tasks)
    const documentsMap = ref(new Map(initialDocs.map(doc => [doc.id, doc])));

    /**
     * expandToParent
     * Internal helper to ensure all ancestors of a document are expanded.
     * Useful for deep-nesting so child tasks don't get lost.
     */
    const expandToParent = (parentId: string | null) => {
        let currentId = parentId;
        while (currentId) {
            expandedRootIds.value.add(currentId);
            const parent = documentsMap.value.get(currentId);
            currentId = parent?.parent_id ?? null;
        }
    };

    // 2. TREE LOGIC ENCAPSULATION
    const allDocs = computed(() => Array.from(documentsMap.value.values()) as ExtendedDocument[]);

    /**
     * PASSING SCHEMA:
     * We pass both the docs AND the schema to useDocumentTree so it
     * knows which types are allowed to be "Roots" (is_task: false).
     */
    const { searchQuery, expandedRootIds, documentTree, toggleRoot } = useDocumentTree(allDocs, schema);

    /**
     * updateDocument
     * THE UNIFIED FUNNEL: Every event (Echo, Form, AI) must pass through here.
     */
    const updateDocument = (id: string | number, data: Partial<ExtendedDocument>) => {
        // Cast ID to string to satisfy the Map<string, ProjectDocument> requirement
        const stringId = String(id);
        const existingDoc = documentsMap.value.get(stringId);

        // 1. If document is new, create it in the Map
        if (!existingDoc) {
            const newDoc = {
                ...data,
                id: stringId, // Ensure the ID matches the string type
                metadata: typeof data.metadata === 'string'
                    ? JSON.parse(data.metadata)
                    : (data.metadata || {})
            } as ExtendedDocument;

            documentsMap.value.set(stringId, newDoc);

            if (newDoc.parent_id) {
                expandToParent(newDoc.parent_id);
            }
            return;
        }

        // 2. If document exists, update it
        const updatedDoc = {
            ...existingDoc,
            ...data,
            metadata: typeof data.metadata === 'string'
                ? JSON.parse(data.metadata)
                : (data.metadata || existingDoc.metadata)
        } as ExtendedDocument;

        documentsMap.value.set(stringId, updatedDoc);

        if (data.parent_id) {
            expandToParent(data.parent_id);
        }
    };

    return {
        documentsMap,
        allDocs,
        searchQuery,
        expandedRootIds,
        documentTree,
        toggleRoot,
        updateDocument
    };
}

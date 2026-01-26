import { ref, computed } from 'vue';
import { useDocumentTree } from '@/composables/useDocumentTree';

export function useProjectState(initialDocs: ProjectDocument[] = []) {
    // 1. CENTRALIZED STATE
    //const documentsMap = ref<Map<string | number, ExtendedDocument>>(new Map());
    const documentsMap = ref<Map<string | number, ProjectDocument>>(
        new Map(initialDocs.map(doc => [doc.id, doc]))
    );

    /**
     * expandToParent
     * Internal helper to ensure all ancestors of a document are expanded.
     * This ensures new AI-generated documents are visible immediately.
     */
    const expandToParent = (parentId: string | number | null) => {
        let currentId = parentId;
        while (currentId) {
            expandedRootIds.value.add(currentId);
            const parent = documentsMap.value.get(currentId);
            currentId = parent?.parent_id ?? null;
        }
    };



    // 3. TREE LOGIC ENCAPSULATION
    const allDocs = computed(() =>
        Array.from(documentsMap.value.values()) as ExtendedDocument[]
    );

    const { searchQuery, expandedRootIds, documentTree, toggleRoot } = useDocumentTree(allDocs);


    /**
     * updateDocument
     * THE UNIFIED FUNNEL: Every event (Echo, Form, AI) must pass through here.
     */

    const updateDocument = (id: string | number, data: Partial<ExtendedDocument>) => {
        const existingDoc = documentsMap.value.get(id);

        // 1. If document is new, create it in the Map
        if (!existingDoc) {
            const newDoc = {
                id,
                ...data,
                metadata: typeof data.metadata === 'string'
                    ? JSON.parse(data.metadata)
                    : (data.metadata || {})
            } as ExtendedDocument;

            documentsMap.value.set(id, newDoc);

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
        };

        documentsMap.value.set(id, updatedDoc);

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

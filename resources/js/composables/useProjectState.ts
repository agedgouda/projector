import { ref, computed, Ref, watch, toValue } from 'vue';
import { useDocumentTree } from '@/composables/useDocumentTree';

export function useProjectState(
    initialDocs: ProjectDocument[] | Ref<ProjectDocument[]> | (() => ProjectDocument[]) = [],
    schema: Ref<DocumentSchemaItem[]>
) {
    // 1. CENTRALIZED STATE
    // Initialize the Map with the current value of the docs
    const documentsMap = ref(new Map(toValue(initialDocs).map(doc => [String(doc.id), doc])));

    /**
     * SYNC INTERNAL STATE:
     * When the parent (Dashboard) updates the liveDocuments array (via Echo/AI),
     * we must update our internal Map so the Hierarchy tab reflects those changes.
     */
    watch(() => toValue(initialDocs), (newDocs) => {
        newDocs.forEach(doc => {
            const stringId = String(doc.id);
            const existing = documentsMap.value.get(stringId);

            // Only update if the data has actually changed or is new
            // This prevents unnecessary re-renders of the tree
            if (!existing || JSON.stringify(existing) !== JSON.stringify(doc)) {
                updateDocument(stringId, doc);
            }
        });
    }, { deep: true });

    /**
     * expandToParent
     * Internal helper to ensure all ancestors of a document are expanded.
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
     * We pass both the docs AND the schema to useDocumentTree.
     */
    const { searchQuery, expandedRootIds, documentTree, toggleRoot } = useDocumentTree(allDocs, schema);

    /**
     * updateDocument
     * THE UNIFIED FUNNEL: Every event (Echo, Form, AI) must pass through here.
     */
    const updateDocument = (id: string | number, data: Partial<ExtendedDocument>) => {
        const stringId = String(id);
        const existingDoc = documentsMap.value.get(stringId);

        // 1. If document is new, create it in the Map
        if (!existingDoc) {
            const newDoc = {
                ...data,
                id: stringId,
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

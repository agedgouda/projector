import { ref, computed, watch, type Ref } from 'vue';
import { useDocumentTree } from '@/composables/useDocumentTree';

export function useProjectState(requirementStatus: Ref<RequirementStatus[]>) {
    // 1. CENTRALIZED STATE
    const documentsMap = ref<Map<string | number, ExtendedDocument>>(new Map());

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

    /**
     * syncFromProps
     * Preserves object references so child components don't re-render unnecessarily.
     */
    const syncFromProps = (statusGroups: RequirementStatus[]) => {
        const incomingIds = new Set<string | number>();

        statusGroups.forEach(group => {
            group.documents.forEach(doc => {
                incomingIds.add(doc.id);
                const existing = documentsMap.value.get(doc.id);

                if (existing) {
                    Object.assign(existing, doc);
                } else {
                    documentsMap.value.set(doc.id, { ...doc } as ExtendedDocument);
                }
            });
        });

        for (const [id] of documentsMap.value) {
            if (!incomingIds.has(id)) {
                documentsMap.value.delete(id);
            }
        }
    };

    // 2. WATCHER
    watch(requirementStatus, (newVal) => syncFromProps(newVal), { deep: true, immediate: true });

    // 3. TREE LOGIC ENCAPSULATION
    const allDocs = computed(() =>
        Array.from(documentsMap.value.values()) as ExtendedDocument[]
    );

    const { searchQuery, expandedRootIds, documentTree, toggleRoot } = useDocumentTree(allDocs);

    // 4. MAPPED REQUIREMENTS
    const localRequirements = computed(() => {
        return requirementStatus.value.map(group => ({
            ...group,
            documents: allDocs.value.filter((d: ExtendedDocument) => d.type === group.key)
        }));
    });

    /**
     * updateDocument
     * THE UNIFIED FUNNEL: Every event (Echo, Form, AI) must pass through here.
     */
    const updateDocument = (id: string | number, data: Partial<ExtendedDocument>) => {
        const existingDoc = documentsMap.value.get(id);
        if (!existingDoc) {
            console.warn(`Document ${id} not found in Map`);
            return;
        }

        // Force a new object reference to trigger Vue's reactivity
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
        localRequirements,
        searchQuery,
        expandedRootIds,
        documentTree,
        toggleRoot,
        updateDocument
    };
}

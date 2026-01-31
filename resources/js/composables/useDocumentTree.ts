import { ref, computed, type Ref } from 'vue';

export function useDocumentTree(
    allDocs: Ref<ExtendedDocument[]>,
    schema: Ref<DocumentSchemaItem[]>
    ) {
    const searchQuery = ref('');
    const expandedRootIds = ref<Set<string | number>>(new Set());

    /**
     * buildTree
     * Recursively builds the hierarchy.
     * Since allDocs is a computed array from our Map,
     * this will re-run whenever a document is added or its parent_id changes.
     */
    const buildTree = (parentId: string | number | null = null): ExtendedDocument[] => {
            return allDocs.value
                .filter(d => {
                    // If we are looking for children, match the parent_id
                    if (parentId !== null) {
                        return d.parent_id === parentId;
                    }

                    // If we are at the ROOT level (parentId is null):
                    // 1. It must not have a parent_id
                    // 2. Its type must be defined as is_task: false in the schema
                    const schemaItem = schema.value.find(s => s.key === d.type);
                    const isTask = schemaItem?.is_task ?? false;

                    return d.parent_id === null && !isTask;
                })
                .map(d => ({
                    ...d,
                    children: buildTree(d.id)
                }));
        };

    const documentTree = computed(() => {
        const query = searchQuery.value.toLowerCase().trim();
        const fullTree = buildTree();

        if (!query) return fullTree;

        // Automatically expand all roots when searching so results aren't hidden
        const filterNodes = (nodes: ExtendedDocument[]): ExtendedDocument[] => {
            return nodes
                .map((node): ExtendedDocument | null => { // 1. Explicitly allow null in map return
                    const filteredChildren = filterNodes(node.children || []);
                    const nameMatch = node.name.toLowerCase().includes(query);

                    if (nameMatch || filteredChildren.length > 0) {
                        if (filteredChildren.length > 0) {
                            expandedRootIds.value.add(node.id);
                        }
                        // 2. Return the full node spread with the new children
                        return {
                            ...node,
                            children: filteredChildren
                        };
                    }
                    return null;
                })
                // 3. Simple filter check
                .filter((n): n is ExtendedDocument => n !== null);
        };

        return filterNodes(fullTree);
    });

    const toggleRoot = (id: string | number) => {
        // We use a new Set to trigger Vue's reactivity properly
        const next = new Set(expandedRootIds.value);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        expandedRootIds.value = next;
    };

    return {
        searchQuery,
        expandedRootIds,
        documentTree,
        toggleRoot
    };
}

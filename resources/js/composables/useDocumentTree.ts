import { ref, computed, type Ref } from 'vue';

export function useDocumentTree(allDocs: Ref<any[]>) {
    const searchQuery = ref('');
    const expandedRootIds = ref<Set<string | number>>(new Set());

    const buildTree = (parentId: string | number | null = null): any[] => {
        return allDocs.value
            .filter(d => d.parent_id === parentId || (parentId === null && d.type === 'intake'))
            .map(d => ({
                ...d,
                children: buildTree(d.id)
            }));
    };

    const documentTree = computed(() => {
        const query = searchQuery.value.toLowerCase().trim();
        const fullTree = buildTree();
        if (!query) return fullTree;

        const filterNodes = (nodes: any[]): any[] => {
            return nodes.map(node => {
                const filteredChildren = filterNodes(node.children || []);
                const nameMatch = node.name.toLowerCase().includes(query);
                if (nameMatch || filteredChildren.length > 0) {
                    return { ...node, children: filteredChildren };
                }
                return null;
            }).filter(n => n !== null);
        };
        return filterNodes(fullTree);
    });

    const toggleRoot = (id: string | number) => {
        if (expandedRootIds.value.has(id)) expandedRootIds.value.delete(id);
        else expandedRootIds.value.add(id);
    };

    return {
        searchQuery,
        expandedRootIds,
        documentTree,
        toggleRoot
    };
}

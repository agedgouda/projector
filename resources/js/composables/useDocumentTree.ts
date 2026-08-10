import { ref, computed, type Ref, watch } from 'vue';
import { INTAKE_KEY } from '@/composables/useWorkflow';

export function useDocumentTree(
    allDocs: Ref<ExtendedDocument[]>,
    schema: Ref<DocumentSchemaItem[]>
) {
    const searchQuery = ref('');

    // --- 1. PERSISTENCE LOGIC ---

    /**
     * Helper to parse the current URL and return a Set of expanded IDs.
     */
    const getExpandedFromUrl = () => {
        if (typeof window === 'undefined') return new Set<string | number>();
        const params = new URLSearchParams(window.location.search);
        const expanded = params.get('expanded');
        return new Set(expanded ? expanded.split(',') : []);
    };

    const expandedRootIds = ref<Set<string | number>>(getExpandedFromUrl());

    /**
     * Sync expanded state to the URL.
     * We use window.history.replaceState to avoid polluting browser history
     * with every single click, but keep the URL current for "Back" button events.
     */
    watch(expandedRootIds, (newSet) => {
        if (typeof window === 'undefined') return;

        const url = new URL(window.location.href);
        const ids = Array.from(newSet).join(',');

        if (ids) {
            url.searchParams.set('expanded', ids);
        } else {
            url.searchParams.delete('expanded');
        }

        window.history.replaceState({}, '', url);
    }, { deep: true });


    // --- 2. TREE BUILDING LOGIC ---

    const buildTree = (parentId: string | number | null = null): ExtendedDocument[] => {
        const nodes = allDocs.value
            .filter(d => {
                if (parentId !== null) {
                    return d.parent_id === parentId;
                }
                return d.parent_id === null;
            })
            .map(d => ({
                ...d,
                children: buildTree(d.id)
            }));

        if (parentId !== null) return nodes;

        // A raw transcript is just noisy source material once it's been processed — the
        // Documentation tab should read as "what came out of this project" (Meeting Notes and
        // beyond), so a processed transcript is replaced at the top level by its own children
        // instead of being shown itself. An unprocessed transcript (no children yet) still has
        // to appear, or it becomes invisible/unreachable from this tab entirely.
        return nodes.flatMap((node) =>
            node.type === INTAKE_KEY && node.children.length > 0 ? node.children : [node]
        );
    };

    const documentTree = computed(() => {
        const query = searchQuery.value.toLowerCase().trim();
        const fullTree = buildTree();

        if (!query) return fullTree;

        const filterNodes = (nodes: ExtendedDocument[]): ExtendedDocument[] => {
            return nodes
                .map((node): ExtendedDocument | null => {
                    const filteredChildren = filterNodes(node.children || []);
                    const nameMatch = node.name.toLowerCase().includes(query);

                    if (nameMatch || filteredChildren.length > 0) {
                        if (filteredChildren.length > 0) {
                            expandedRootIds.value.add(node.id);
                        }
                        return { ...node, children: filteredChildren };
                    }
                    return null;
                })
                .filter((n): n is ExtendedDocument => n !== null);
        };

        return filterNodes(fullTree);
    });

    // --- 3. ACTIONS ---

    const toggleRoot = (id: string | number) => {
        const next = new Set(expandedRootIds.value);
        // Ensure we compare strings to avoid type mismatches from URL parsing
        const stringId = String(id);

        // Check for both the raw ID and stringified ID
        if (next.has(id) || next.has(stringId)) {
            next.delete(id);
            next.delete(stringId);
        } else {
            next.add(stringId);
        }
        expandedRootIds.value = next;
    };

    return {
        searchQuery,
        expandedRootIds,
        documentTree,
        toggleRoot
    };
}

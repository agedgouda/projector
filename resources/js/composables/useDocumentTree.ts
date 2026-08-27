import { INTAKE_KEY } from '@/composables/useWorkflow';
import { computed, ref, type Ref, watch } from 'vue';

export function useDocumentTree(
    allDocs: Ref<ExtendedDocument[]>,
    schema: Ref<DocumentSchemaItem[]>,
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
    watch(
        expandedRootIds,
        (newSet) => {
            if (typeof window === 'undefined') return;

            const url = new URL(window.location.href);
            const ids = Array.from(newSet).join(',');

            if (ids) {
                url.searchParams.set('expanded', ids);
            } else {
                url.searchParams.delete('expanded');
            }

            window.history.replaceState({}, '', url);
        },
        { deep: true },
    );

    // --- 2. FLAT LIST LOGIC ---

    const isTaskType = (typeKey: string | null | undefined): boolean => {
        if (!typeKey) return false;
        return !!schema.value.find((s) => s.key === typeKey)?.is_task;
    };

    // A raw transcript is just noisy source material once something's been generated from it —
    // still excluded here even in a flat list, same as before nesting was removed. An
    // unprocessed transcript (no children yet) still has to appear, or it becomes
    // invisible/unreachable from this tab entirely.
    const hasChildren = computed(() => {
        const parentIds = new Set<string>();
        for (const doc of allDocs.value) {
            if (doc.parent_id != null) parentIds.add(String(doc.parent_id));
        }
        return parentIds;
    });

    // Every document on this tab as one flat list — Tasks (shown on the Tasks tab) and Events
    // (shown on the Campaign Calendar) are excluded entirely, and nothing nests under its
    // parent: the traceability chain still exists in the data (View Source/Generated X links
    // on the document's own page), it's just not rendered as an expandable tree here.
    const documentTree = computed(() => {
        const query = searchQuery.value.toLowerCase().trim();

        return allDocs.value
            .filter((d) => {
                if (d.type === 'event') return false;
                if (isTaskType(d.type)) return false;
                if (
                    d.type === INTAKE_KEY &&
                    hasChildren.value.has(String(d.id))
                ) {
                    return false;
                }
                if (query && !d.name.toLowerCase().includes(query))
                    return false;
                return true;
            })
            .map((d) => ({ ...d, children: [] as ExtendedDocument[] }));
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
        toggleRoot,
    };
}

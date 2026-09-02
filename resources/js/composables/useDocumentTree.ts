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

    const getGroupLabel = (typeKey: string) =>
        schema.value.find((item) => item.key === typeKey)?.label ||
        typeKey.replace(/_/g, ' ');

    // Every document eligible for this tab — Tasks (shown on the Tasks tab) and Events (shown
    // on the Campaign Calendar) are excluded entirely. The old parent->child traceability chain
    // (intake -> its generated document) still exists in the data (View Source/Generated X
    // links on the document's own page); it's not what this list nests on.
    const filteredDocs = computed(() => {
        const query = searchQuery.value.toLowerCase().trim();

        return allDocs.value.filter((d) => {
            if (d.type === 'event') return false;
            if (isTaskType(d.type)) return false;
            if (d.type === INTAKE_KEY && hasChildren.value.has(String(d.id))) {
                return false;
            }
            if (query && !d.name.toLowerCase().includes(query)) return false;
            return true;
        });
    });

    // One folder row per document type present, each nesting that type's documents as
    // children — ordered to match the project's own document_schema sequence (falling back to
    // first-appearance order for a type no longer in the schema, e.g. after a protocol change)
    // rather than alphabetically, so it reads the same left-to-right order used everywhere
    // else in the app that's driven by this same schema.
    const documentTree = computed(() => {
        const byType = new Map<string, ExtendedDocument[]>();
        filteredDocs.value.forEach((d) => {
            if (!byType.has(d.type)) byType.set(d.type, []);
            byType.get(d.type)!.push(d);
        });

        const schemaKeys = schema.value.map((s) => s.key);
        const orderedKeys = [
            ...schemaKeys.filter((key) => byType.has(key)),
            ...Array.from(byType.keys()).filter(
                (key) => !schemaKeys.includes(key),
            ),
        ];

        return orderedKeys.map((key) => ({
            id: `type:${key}`,
            type: '__type_group__',
            isTypeGroup: true,
            name: getGroupLabel(key),
            children: byType
                .get(key)!
                .map((d) => ({ ...d, children: [] as ExtendedDocument[] })),
        }));
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

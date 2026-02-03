import { ref } from 'vue';

export function useResourceExpansion<T extends { id: string | number }>(items: T[]) {
    // Initialize all items as collapsed (true)
    const collapsedStates = ref<Record<string | number, boolean>>(
        Object.fromEntries(items.map(item => [item.id, true]))
    );

    const toggle = (id: string | number) => {
        collapsedStates.value[id] = !collapsedStates.value[id];
    };

    const handleSearchExpand = (ids: (string | number)[]) => {
        // Reset all to collapsed (true)
        Object.keys(collapsedStates.value).forEach(key => {
            collapsedStates.value[key] = true;
        });

        // Expand the matches (set to false)
        ids.forEach(id => {
            collapsedStates.value[id] = false;
        });
    };

    return {
        collapsedStates,
        toggle,
        handleSearchExpand
    };
}

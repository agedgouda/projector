import { ref, computed } from 'vue';
import type { KanbanProps } from './useKanbanBoard';

export function useKanbanQueries(props: KanbanProps, localKanbanData: any) {
    const searchQuery = ref('');

    /**
     * MEMOIZED TASK MAP
     * We group tasks by a unique string key "rowKey|status"
     * This turns an O(n*m) operation into an O(1) lookup.
     */
    const taskMap = computed(() => {
        const map: Record<string, ProjectDocument[]> = {};
        const query = searchQuery.value.toLowerCase().trim();

        Object.entries(localKanbanData.value as Record<string, ProjectDocument[]>).forEach(([rowKey, tasks]) => {
            tasks.forEach(doc => {
                // 1. Calculate status (with fallback)
                const status = (doc.task_status || doc.status || 'todo') as TaskStatus;

                // 2. Apply search filter
                const matchesSearch = !query ||
                    doc.name.toLowerCase().includes(query) ||
                    doc.assignee?.name.toLowerCase().includes(query);

                if (matchesSearch) {
                    const compositeKey = `${rowKey}|${status}`;
                    if (!map[compositeKey]) map[compositeKey] = [];
                    map[compositeKey].push(doc);
                }
            });
        });
        return map;
    });

    /**
     * O(1) Lookup - Super fast
     */
    const getTasksByRowAndStatus = (rowKey: string, status: TaskStatus) => {
        return taskMap.value[`${rowKey}|${status}`] || [];
    };

    /**
     * Totals tasks across rows for a header count
     */
    const getColumnTaskCount = (status: TaskStatus) => {
        let count = 0;
        // We sum up all keys in our map that end with our target status
        Object.keys(taskMap.value).forEach(key => {
            if (key.endsWith(`|${status}`)) {
                count += taskMap.value[key].length;
            }
        });
        return count;
    };

    return {
        searchQuery,
        getTasksByRowAndStatus,
        getColumnTaskCount
    };
}

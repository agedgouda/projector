import { ref, computed } from 'vue';
import type { KanbanProps } from './useKanbanBoard';

const PRIORITY_ORDER: Record<string, number> = { high: 0, medium: 1, low: 2 };
export const ALL_PRIORITIES = ['high', 'medium', 'low'] as const;
export type Priority = typeof ALL_PRIORITIES[number];

const sortTasks = (tasks: ProjectDocument[]): ProjectDocument[] =>
    [...tasks].sort((a, b) => {
        const pa = PRIORITY_ORDER[a.priority?.toLowerCase() ?? ''] ?? 3;
        const pb = PRIORITY_ORDER[b.priority?.toLowerCase() ?? ''] ?? 3;
        if (pa !== pb) return pa - pb;
        if (!a.due_at && !b.due_at) return 0;
        if (!a.due_at) return 1;
        if (!b.due_at) return -1;
        return new Date(a.due_at).getTime() - new Date(b.due_at).getTime();
    });

export function useKanbanQueries(props: KanbanProps, localKanbanData: any) {
    const searchQuery = ref('');
    const selectedPriorities = ref<Priority[]>([...ALL_PRIORITIES]);

    /**
     * MEMOIZED TASK MAP
     * We group tasks by a unique string key "rowKey|status"
     * This turns an O(n*m) operation into an O(1) lookup.
     */
    const taskMap = computed(() => {
        const map: Record<string, ProjectDocument[]> = {};
        const query = searchQuery.value.toLowerCase().trim();
        const priorities = new Set(selectedPriorities.value);

        Object.entries(localKanbanData.value as Record<string, ProjectDocument[]>).forEach(([rowKey, tasks]) => {
            tasks.forEach(doc => {
                // 1. Calculate status (with fallback)
                const status = (doc.task_status || doc.status || 'todo') as TaskStatus;

                // 2. Apply search filter
                const matchesSearch = !query ||
                    doc.name.toLowerCase().includes(query) ||
                    doc.assignee?.name.toLowerCase().includes(query);

                // 3. Apply priority filter (tasks with no priority pass through when any priority is selected)
                const docPriority = doc.priority?.toLowerCase() as Priority | undefined;
                const matchesPriority = !docPriority || priorities.has(docPriority);

                if (matchesSearch && matchesPriority) {
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
        return sortTasks(taskMap.value[`${rowKey}|${status}`] || []);
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
        selectedPriorities,
        getTasksByRowAndStatus,
        getColumnTaskCount
    };
}

import { computed, ref } from 'vue';
import type { KanbanProps } from './useKanbanBoard';

export const ALL_PRIORITIES = ['high', 'medium', 'low'] as const;
export type Priority = (typeof ALL_PRIORITIES)[number];

export type SortOption = 'due_date' | 'priority' | 'created_at';
export const SORT_OPTIONS: { value: SortOption; label: string }[] = [
    { value: 'due_date', label: 'Due Date' },
    { value: 'priority', label: 'Priority' },
    { value: 'created_at', label: 'Created Date' },
];

const PRIORITY_WEIGHT: Record<string, number> = {
    urgent: 4,
    high: 3,
    medium: 2,
    low: 1,
};

const sortTasks = (
    tasks: ProjectDocument[],
    sortBy: SortOption,
): ProjectDocument[] =>
    [...tasks].sort((a, b) => {
        if (sortBy === 'priority') {
            const wa = PRIORITY_WEIGHT[a.priority?.toLowerCase() ?? ''] ?? 0;
            const wb = PRIORITY_WEIGHT[b.priority?.toLowerCase() ?? ''] ?? 0;
            return wb - wa;
        }

        if (sortBy === 'created_at') {
            return (
                new Date(b.created_at).getTime() -
                new Date(a.created_at).getTime()
            );
        }

        // due_date (default): soonest due date first, undated tasks last
        if (!a.due_at && !b.due_at) return 0;
        if (!a.due_at) return 1;
        if (!b.due_at) return -1;
        return new Date(a.due_at).getTime() - new Date(b.due_at).getTime();
    });

export function useKanbanQueries(props: KanbanProps, localKanbanData: any) {
    const searchQuery = ref('');
    const selectedPriorities = ref<Priority[]>([...ALL_PRIORITIES]);
    const sortBy = ref<SortOption>('due_date');

    /**
     * MEMOIZED TASK MAP
     * We group tasks by a unique string key "rowKey|status"
     * This turns an O(n*m) operation into an O(1) lookup.
     */
    const taskMap = computed(() => {
        const map: Record<string, ProjectDocument[]> = {};
        const query = searchQuery.value.toLowerCase().trim();
        const priorities = new Set(selectedPriorities.value);

        Object.entries(
            localKanbanData.value as Record<string, ProjectDocument[]>,
        ).forEach(([rowKey, tasks]) => {
            tasks.forEach((doc) => {
                // 1. Calculate status (with fallback)
                const status = (doc.task_status ||
                    doc.status ||
                    'todo') as TaskStatus;

                // 2. Apply search filter
                const matchesSearch =
                    !query ||
                    doc.name.toLowerCase().includes(query) ||
                    doc.assignee?.name.toLowerCase().includes(query);

                // 3. Apply priority filter (tasks with no priority pass through when any priority is selected)
                const docPriority = doc.priority?.toLowerCase() as
                    | Priority
                    | undefined;
                const matchesPriority =
                    !docPriority || priorities.has(docPriority);

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
        return sortTasks(
            taskMap.value[`${rowKey}|${status}`] || [],
            sortBy.value,
        );
    };

    return {
        searchQuery,
        selectedPriorities,
        sortBy,
        getTasksByRowAndStatus,
    };
}

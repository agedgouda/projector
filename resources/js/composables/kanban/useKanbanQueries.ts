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

    // Every distinct tag currently in view (across every row/status), deduped by id — the
    // filter's own candidate list, since tags are per-project-family and dynamic rather than
    // a fixed set like priorities.
    const availableTags = computed<CategoryDef[]>(() => {
        const byId = new Map<string, CategoryDef>();
        Object.values(
            localKanbanData.value as Record<string, ProjectDocument[]>,
        ).forEach((tasks) => {
            tasks.forEach((doc) => {
                if (doc.category && !byId.has(doc.category.id)) {
                    byId.set(doc.category.id, doc.category);
                }
            });
        });
        return Array.from(byId.values()).sort((a, b) =>
            a.name.localeCompare(b.name),
        );
    });

    // Tags unchecked in the filter — empty by default, so nothing is excluded until the user
    // deselects something (mirrors "All" being the default state). A tagged task is hidden
    // once its tag is excluded; an untagged task always passes through, same pass-through
    // rule the priority filter already uses for undocumented priority.
    const excludedTagIds = ref<string[]>([]);

    /**
     * MEMOIZED TASK MAP
     * We group tasks by a unique string key "rowKey|status"
     * This turns an O(n*m) operation into an O(1) lookup.
     */
    const taskMap = computed(() => {
        const map: Record<string, ProjectDocument[]> = {};
        const query = searchQuery.value.toLowerCase().trim();
        const priorities = new Set(selectedPriorities.value);
        const excludedTags = new Set(excludedTagIds.value);

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

                // 4. Apply tag filter (tasks with no tag always pass through)
                const matchesTag =
                    !doc.category || !excludedTags.has(doc.category.id);

                if (matchesSearch && matchesPriority && matchesTag) {
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
        availableTags,
        excludedTagIds,
        getTasksByRowAndStatus,
    };
}

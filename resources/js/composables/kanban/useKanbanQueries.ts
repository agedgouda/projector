import { computed, ref } from 'vue';
import { mergeAssigneeOptions, type AssigneeOption } from '@/lib/assignees';
import type { KanbanProps } from './useKanbanBoard';

export const ALL_PRIORITIES = ['high', 'medium', 'low'] as const;
export type Priority = (typeof ALL_PRIORITIES)[number];

export type SortOption = 'due_date' | 'priority' | 'created_at';
export const SORT_OPTIONS: { value: SortOption; label: string }[] = [
    { value: 'due_date', label: 'Due Date' },
    { value: 'priority', label: 'Priority' },
    { value: 'created_at', label: 'Created Date' },
];

// Multi-select tag filter: an empty selection shows everything ("All"). Otherwise a task
// matches if any of its tags is selected, or — via the 'none' sentinel — if it has no tags
// at all. Tag ids are UUIDs, so they never collide with the sentinel string.
export const TAG_FILTER_NONE = 'none';

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
                (doc.categories ?? []).forEach((category) => {
                    if (!byId.has(category.id)) byId.set(category.id, category);
                });
            });
        });
        return Array.from(byId.values()).sort((a, b) =>
            a.name.localeCompare(b.name),
        );
    });

    const selectedTagIds = ref<string[]>([]);

    // Every task's project, by id — a KanbanCard needs its own document's project (for its
    // tags, client, and assignable users), but doing that lookup by scanning props.projects
    // is O(1) via this Map instead of a fresh linear .find() per card.
    const projectsById = computed<Map<string, Project>>(() => {
        const map = new Map<string, Project>();
        (props.projects ?? []).forEach((project) => map.set(project.id, project));
        return map;
    });

    // Assignee options are identical for every task on the same project, but merging +
    // sorting an organization's users/invitations is real work — doing it once per project
    // here (instead of once per KanbanCard instance, as before) avoids redoing the same
    // merge dozens of times over when many cards from the same project are visible at once.
    const assigneeOptionsByProjectId = computed<Map<string, AssigneeOption[]>>(() => {
        const map = new Map<string, AssigneeOption[]>();
        (props.projects ?? []).forEach((project) => {
            map.set(
                project.id,
                mergeAssigneeOptions(
                    project.client?.organization?.users,
                    project.client?.organization?.invitations,
                ),
            );
        });
        return map;
    });

    /**
     * MEMOIZED TASK MAP
     * We group tasks by a unique string key "rowKey|status". Deliberately NOT filtered by
     * search/priority/tag here — every task for a given row+status is always included, so
     * toggling a filter never changes which cards are mounted (see matchesFilters() below).
     * Filtering out and back in used to remove/re-add cards from this map, which meant
     * every filter change forced Vue to mount a fresh KanbanCard (and its several child
     * Select/Popover components) for every newly-shown task — real, visible cost that scaled
     * with how many cards a filter revealed. A hidden card costs a style recalculation now,
     * not a mount.
     */
    const taskMap = computed(() => {
        const map: Record<string, ProjectDocument[]> = {};

        Object.entries(
            localKanbanData.value as Record<string, ProjectDocument[]>,
        ).forEach(([rowKey, tasks]) => {
            tasks.forEach((doc) => {
                const status = (doc.task_status ||
                    doc.status ||
                    'todo') as TaskStatus;
                const compositeKey = `${rowKey}|${status}`;
                if (!map[compositeKey]) map[compositeKey] = [];
                map[compositeKey].push(doc);
            });
        });
        return map;
    });

    // Sorted once per composite key here, memoized alongside taskMap — not inside
    // getTasksByRowAndStatus, which the template calls afresh on every render (once for
    // each column's card list, again for its header count), and would otherwise re-sort
    // the same array repeatedly on every re-render.
    const sortedTaskMap = computed(() => {
        const map: Record<string, ProjectDocument[]> = {};
        Object.entries(taskMap.value).forEach(([key, tasks]) => {
            map[key] = sortTasks(tasks, sortBy.value);
        });
        return map;
    });

    /**
     * O(1) Lookup - Super fast. Returns every task for this row+status (unfiltered) — the
     * caller is responsible for hiding the ones matchesFilters() rejects, not for excluding them.
     */
    const getTasksByRowAndStatus = (rowKey: string, status: TaskStatus) => {
        return sortedTaskMap.value[`${rowKey}|${status}`] || [];
    };

    // Whether a single task currently matches the search/priority/tag filters — cheap (a
    // handful of comparisons), and cheap is the point: called once per already-mounted card
    // on every filter change, instead of the whole board re-mounting cards to match.
    const matchesFilters = (doc: ProjectDocument): boolean => {
        const query = searchQuery.value.toLowerCase().trim();
        const matchesSearch =
            !query ||
            doc.name.toLowerCase().includes(query) ||
            doc.assignee?.name.toLowerCase().includes(query);
        if (!matchesSearch) return false;

        const docPriority = doc.priority?.toLowerCase() as
            | Priority
            | undefined;
        const matchesPriority =
            !docPriority || selectedPriorities.value.includes(docPriority);
        if (!matchesPriority) return false;

        if (selectedTagIds.value.length === 0) return true;

        const docCategories = doc.categories ?? [];
        if (docCategories.length === 0) {
            return selectedTagIds.value.includes(TAG_FILTER_NONE);
        }
        return docCategories.some((c) => selectedTagIds.value.includes(c.id));
    };

    // Count of VISIBLE (filter-matching) tasks for a column header — deliberately re-derived
    // from matchesFilters() rather than taskMap's raw length, since taskMap is unfiltered now.
    const getTaskCountByRowAndStatus = (rowKey: string, status: TaskStatus) => {
        return (taskMap.value[`${rowKey}|${status}`] || []).filter(
            matchesFilters,
        ).length;
    };

    return {
        searchQuery,
        selectedPriorities,
        sortBy,
        availableTags,
        selectedTagIds,
        getTasksByRowAndStatus,
        getTaskCountByRowAndStatus,
        matchesFilters,
        projectsById,
        assigneeOptionsByProjectId,
    };
}

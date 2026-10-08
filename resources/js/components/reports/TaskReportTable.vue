<script setup lang="ts">
import DateField from '@/components/DateField.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
} from '@/components/ui/select';
import { invitationName, type AssigneeOption } from '@/lib/assignees';
import { kanbanDotClasses } from '@/lib/constants';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';
import { followersByPredecessor, orderAsChains } from '@/lib/taskChains';
import { formatDateOnly } from '@/lib/utils';
import {
    ChevronDown,
    ChevronsUpDown,
    ChevronUp,
    CornerDownRight,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

export interface TaskReportRow {
    id: string | number;
    project_id: string;
    project_name: string | null;
    name: string;
    due_at: string | null;
    external_due_at: string | null;
    start_at: string | null;
    predecessor_id?: string | null;
    status_changed_at: string | null;
    priority: string | null;
    task_status: string | null;
    assignee_id: number | null;
    pending_assignee_invitation_id: number | null;
    assignee: { id: number; name: string } | null;
    pending_assignee: {
        id: number;
        email: string;
        first_name: string | null;
        last_name: string | null;
    } | null;
    categories: CategoryDef[];
    // Everything below is only used to open this row in the slide-in detail sheet (see
    // TaskReport.vue), not rendered anywhere in the table itself.
    content: string | null;
    type: string;
    custom_prompt: string | null;
    locked_project_type_id: string | null;
    locked_next_workflow_step_exists: boolean;
    last_ai_template_id: number | null;
    processed_at: string | null;
    updated_at: string;
    comments: Comment[];
}

export type SortKey =
    | 'status'
    | 'start_at'
    | 'due_at'
    | 'status_changed_at'
    | 'external_due_at'
    | 'name'
    | 'assignee'
    | 'project_name';
export type SortDir = 'asc' | 'desc';
// What the exports are asked to replicate: a column sort, or chain order (the default while the
// org tracks task start dates — see TaskReportBuilder::orderAsChains()).
export type ExportSortKey = SortKey | 'chain';

const props = defineProps<{
    tasks: TaskReportRow[];
    columns?: KanbanColumnDef[];
    usesExternalDueDates?: boolean;
    usesTaskStartDates?: boolean;
    // Which date the primary due-date column is showing/filtering — 'due' (default) shows
    // due_at, editable inline same as always; 'done' shows the read-only status_changed_at
    // instead (see ReportController::buildTasksQuery()'s own mode handling).
    mode?: 'due' | 'done';
    hasSubprojects?: boolean;
    assigneeOptions?: AssigneeOption[];
    // The task family's full tag catalog (see Project::familyCategories()) — offered as
    // "add a tag" options on every row regardless of which sub-project it's on, since tags
    // are shared at the family root (DocumentController::updateAttributes() validates
    // against $project->familyRoot(), not the task's own immediate project).
    categories?: CategoryDef[];
}>();

const emit = defineEmits<{
    (e: 'sort-change', key: ExportSortKey, dir: SortDir): void;
    // Field-level edits (status/due dates/assignee) — one attribute at a time, mirroring
    // TaskRowFields.vue/AssigneeAvatar.vue's own 'update' event.
    (
        e: 'update-field',
        task: TaskReportRow,
        field: string,
        value: unknown,
    ): void;
    // Clicking a row opens it in the slide-in detail sheet instead of navigating away —
    // same as the Kanban board's own card click (see useKanbanState.ts's openDetail).
    (e: 'open-detail', task: TaskReportRow): void;
}>();

const statusFor = (statusKey: string | null) =>
    props.columns?.find((c) => c.key === statusKey);

// Real, individually fixed-width grid columns (not a flexed group) — each row's Status
// and due date(s) need to land in the same place, which only a shared column grid
// guarantees. Due date(s) sit last, all the way to the right of the row. "Internal"/
// "External" stack over "Due" in the header (see template) so each due column's header
// text only needs to fit one of those words — the body cell below it is a DateField (see
// template), sized ~112px so its own text has room at this row's text-[13px] size (same
// figure TaskRowFields.vue's own date field is sized to, for the same reason). Project
// (when shown) always leads, since it's the grouping-level field.
//
// A Start column (orgs tracking task start dates) sits just before the due date(s).
//
// All eight combinations are spelled out as complete literal strings (not built via string
// concatenation) since Tailwind's build-time scanner only picks up class names that appear
// verbatim in source — a runtime-assembled arbitrary-value class like grid-cols-[...] would
// silently fail to generate any CSS.
const gridColsClass = computed(() => {
    if (props.usesTaskStartDates) {
        if (props.hasSubprojects && props.usesExternalDueDates) {
            return 'md:grid-cols-[140px_110px_1fr_180px_112px_112px_112px]';
        }
        if (props.hasSubprojects) {
            return 'md:grid-cols-[140px_110px_1fr_180px_112px_112px]';
        }
        if (props.usesExternalDueDates) {
            return 'md:grid-cols-[110px_1fr_180px_112px_112px_112px]';
        }
        return 'md:grid-cols-[110px_1fr_180px_112px_112px]';
    }
    if (props.hasSubprojects && props.usesExternalDueDates) {
        return 'md:grid-cols-[140px_110px_1fr_180px_112px_112px]';
    }
    if (props.hasSubprojects) {
        return 'md:grid-cols-[140px_110px_1fr_180px_112px]';
    }
    if (props.usesExternalDueDates) {
        return 'md:grid-cols-[110px_1fr_180px_112px_112px]';
    }
    return 'md:grid-cols-[110px_1fr_180px_112px]';
});

const assigneeLabel = (task: TaskReportRow): string => {
    if (task.assignee) return task.assignee.name;
    if (task.pending_assignee)
        return invitationName(
            task.pending_assignee as unknown as OrganizationInvitation,
        );
    return 'Unassigned';
};

// Rows are a plain div (not a <Link>/<a>) precisely so the Select/date-input controls
// below can live inside them — nesting interactive elements like <button> and <select>
// inside an anchor is invalid HTML, the same reason TraceabilityRow.vue's task rows use a
// div-plus-click here rather than a real link. Every editable control below stops its own
// click from bubbling here (see each `@click.stop`), so only the dead space around them —
// and the task name itself — open the row's detail sheet.
const openDetail = (task: TaskReportRow) => emit('open-detail', task);

const dueDateInputValue = (value: string | null): string =>
    value ? value.slice(0, 10) : '';

// Mirrors AssigneeAvatar.vue's own assigneeValue: a real user's id, an `inv:`-prefixed
// pending-invitation id, or the unassigned sentinel — the three states the Select below
// offers.
const assigneeSelectValue = (task: TaskReportRow): string => {
    if (task.pending_assignee_invitation_id)
        return `inv:${task.pending_assignee_invitation_id}`;
    return task.assignee_id?.toString() ?? 'unassigned';
};

const sortKey = ref<SortKey>('due_at');
// The column whose sort arrow shows — none while the table is in chain order.
const activeSortKey = computed<SortKey | null>(() =>
    chainOrder.value && props.usesTaskStartDates ? null : sortKey.value,
);
const sortDir = ref<SortDir>('asc');

// The primary due-date column's own header/sort-toggle follows whichever field it's actually
// showing — status_changed_at in Done mode, due_at otherwise. Computed once rather than
// repeating the mode ternary at each of the header's several usages below.
const dueSortKey = computed<SortKey>(() =>
    props.mode === 'done' ? 'status_changed_at' : 'due_at',
);

// The export buttons (see TaskReport.vue) build a fresh document server-side, so they
// can't just re-sort whatever's already on screen the way this table does — they need
// to know the current sort to ask the backend to replicate it.
const toggleSort = (key: SortKey) => {
    if (chainOrder.value) {
        chainOrder.value = false;
        sortKey.value = key;
        sortDir.value = 'asc';
        emit('sort-change', sortKey.value, sortDir.value);
        return;
    }
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortDir.value = 'asc';
    }
    emit('sort-change', sortKey.value, sortDir.value);
};

// Nulls always sort last, regardless of direction — an unset due date shouldn't jump to
// the top just because the sort direction flipped to descending.
const compare = (
    a: string | number | null,
    b: string | number | null,
): number => {
    if (a === null && b === null) return 0;
    if (a === null) return 1;
    if (b === null) return -1;
    if (a < b) return -1;
    if (a > b) return 1;
    return 0;
};

const sortValue = (
    task: TaskReportRow,
    key: SortKey,
): string | number | null => {
    switch (key) {
        case 'status':
            return statusFor(task.task_status)?.order ?? null;
        case 'start_at':
            return task.start_at;
        case 'due_at':
            return task.due_at;
        case 'status_changed_at':
            return task.status_changed_at;
        case 'external_due_at':
            return task.external_due_at;
        case 'name':
            return task.name.toLowerCase();
        case 'assignee':
            return assigneeLabel(task).toLowerCase();
        case 'project_name':
            return task.project_name ? task.project_name.toLowerCase() : null;
    }
};

const sortedTasks = computed(() => {
    const direction = sortDir.value === 'asc' ? 1 : -1;

    return [...props.tasks].sort(
        (a, b) =>
            direction *
            compare(sortValue(a, sortKey.value), sortValue(b, sortKey.value)),
    );
});

// --- Task connections (orgs that track task start dates) ---
// Chain order (each task nested under the one it waits on — see lib/taskChains.ts) is the
// default; sorting by any column flattens the table, with the link markers below still showing
// who waits on whom, and "Chain order" in the Task Name header switches back.
const chainOrder = ref(!!props.usesTaskStartDates);
const showConnections = computed(() => !!props.usesTaskStartDates);

const returnToChainOrder = () => {
    chainOrder.value = true;
    sortKey.value = 'due_at';
    sortDir.value = 'asc';
    emit('sort-change', 'chain', 'asc');
};

const displayRows = computed(() =>
    showConnections.value && chainOrder.value
        ? orderAsChains(props.tasks)
        : sortedTasks.value.map((item) => ({
              item,
              depth: 0,
              hasFollowers: false,
          })),
);

const tasksById = computed(
    () => new Map(props.tasks.map((task) => [String(task.id), task])),
);
const followersOf = computed(() => followersByPredecessor(props.tasks));

// Counts only tasks in this report — the filters may leave some out.
const followerCount = (task: TaskReportRow) =>
    followersOf.value.get(String(task.id))?.length ?? 0;

const waitsOnTitle = (task: TaskReportRow) => {
    const predecessor = task.predecessor_id
        ? tasksById.value.get(task.predecessor_id)
        : undefined;
    return predecessor
        ? `Waits on "${predecessor.name}"`
        : 'Waits on a task not in this report';
};

// Hovering a row tints the task it waits on and the tasks that wait on it.
const hoveredId = ref<string | null>(null);
const relatedIds = computed(() => {
    const related = new Set<string>();
    const hovered = hoveredId.value
        ? tasksById.value.get(hoveredId.value)
        : undefined;
    if (!hovered || !showConnections.value) return related;
    if (hovered.predecessor_id) related.add(hovered.predecessor_id);
    for (const follower of followersOf.value.get(String(hovered.id)) ?? []) {
        related.add(String(follower.id));
    }
    return related;
});
</script>

<template>
    <div class="grid gap-0.5">
        <div
            :class="[
                'hidden items-center gap-3 px-4 py-2 text-[10px] font-black tracking-[0.2em] text-slate-400 uppercase md:grid',
                gridColsClass,
            ]"
        >
            <button
                v-if="hasSubprojects"
                type="button"
                class="flex items-center gap-1 hover:text-slate-600 dark:hover:text-slate-300"
                @click="toggleSort('project_name')"
            >
                Project
                <ChevronUp
                    v-if="activeSortKey === 'project_name' && sortDir === 'asc'"
                    class="h-3 w-3"
                />
                <ChevronDown
                    v-else-if="
                        activeSortKey === 'project_name' && sortDir === 'desc'
                    "
                    class="h-3 w-3"
                />
                <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
            </button>

            <button
                type="button"
                class="flex items-center gap-1 hover:text-slate-600 dark:hover:text-slate-300"
                @click="toggleSort('status')"
            >
                Status
                <ChevronUp
                    v-if="activeSortKey === 'status' && sortDir === 'asc'"
                    class="h-3 w-3"
                />
                <ChevronDown
                    v-else-if="activeSortKey === 'status' && sortDir === 'desc'"
                    class="h-3 w-3"
                />
                <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
            </button>

            <div class="flex items-center gap-3">
                <button
                    type="button"
                    class="flex items-center gap-1 hover:text-slate-600 dark:hover:text-slate-300"
                    @click="toggleSort('name')"
                >
                    Task Name
                    <ChevronUp
                        v-if="activeSortKey === 'name' && sortDir === 'asc'"
                        class="h-3 w-3"
                    />
                    <ChevronDown
                        v-else-if="
                            activeSortKey === 'name' && sortDir === 'desc'
                        "
                        class="h-3 w-3"
                    />
                    <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
                </button>
                <button
                    v-if="showConnections && !chainOrder"
                    type="button"
                    class="tracking-[0.1em] text-projector-primary-600 normal-case hover:text-projector-primary-700 dark:text-projector-primary-400"
                    title="List each task under the one it waits on"
                    @click="returnToChainOrder"
                >
                    Chain order
                </button>
            </div>

            <button
                type="button"
                class="flex items-center gap-1 hover:text-slate-600 dark:hover:text-slate-300"
                @click="toggleSort('assignee')"
            >
                Assignee
                <ChevronUp
                    v-if="activeSortKey === 'assignee' && sortDir === 'asc'"
                    class="h-3 w-3"
                />
                <ChevronDown
                    v-else-if="
                        activeSortKey === 'assignee' && sortDir === 'desc'
                    "
                    class="h-3 w-3"
                />
                <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
            </button>

            <button
                v-if="usesTaskStartDates"
                type="button"
                class="flex items-center justify-center gap-1 leading-tight hover:text-slate-600 dark:hover:text-slate-300"
                @click="toggleSort('start_at')"
            >
                <span class="flex flex-col items-center text-center">
                    <span>Start</span>
                    <span>Date</span>
                </span>
                <ChevronUp
                    v-if="activeSortKey === 'start_at' && sortDir === 'asc'"
                    class="h-3 w-3 shrink-0"
                />
                <ChevronDown
                    v-else-if="
                        activeSortKey === 'start_at' && sortDir === 'desc'
                    "
                    class="h-3 w-3 shrink-0"
                />
                <ChevronsUpDown v-else class="h-3 w-3 shrink-0 opacity-40" />
            </button>

            <button
                type="button"
                class="flex items-center justify-center gap-1 leading-tight hover:text-slate-600 dark:hover:text-slate-300"
                @click="toggleSort(dueSortKey)"
            >
                <span class="flex flex-col items-center text-center">
                    <span v-if="mode === 'done'">Done</span>
                    <template v-else-if="usesExternalDueDates">
                        <span>Internal</span>
                        <span>Due</span>
                    </template>
                    <span v-else>Due Date</span>
                </span>
                <ChevronUp
                    v-if="activeSortKey === dueSortKey && sortDir === 'asc'"
                    class="h-3 w-3 shrink-0"
                />
                <ChevronDown
                    v-else-if="
                        activeSortKey === dueSortKey && sortDir === 'desc'
                    "
                    class="h-3 w-3 shrink-0"
                />
                <ChevronsUpDown v-else class="h-3 w-3 shrink-0 opacity-40" />
            </button>

            <button
                v-if="usesExternalDueDates"
                type="button"
                class="flex items-center justify-center gap-1 leading-tight hover:text-slate-600 dark:hover:text-slate-300"
                @click="toggleSort('external_due_at')"
            >
                <span class="flex flex-col items-center text-center">
                    <span>External</span>
                    <span>Due</span>
                </span>
                <ChevronUp
                    v-if="
                        activeSortKey === 'external_due_at' && sortDir === 'asc'
                    "
                    class="h-3 w-3 shrink-0"
                />
                <ChevronDown
                    v-else-if="
                        activeSortKey === 'external_due_at' &&
                        sortDir === 'desc'
                    "
                    class="h-3 w-3 shrink-0"
                />
                <ChevronsUpDown v-else class="h-3 w-3 shrink-0 opacity-40" />
            </button>
        </div>

        <div
            v-for="{ item: task, depth } in displayRows"
            :key="task.id"
            :class="[
                'grid cursor-pointer grid-cols-2 items-center gap-2 rounded-md px-4 py-3 text-[13px] transition-colors md:gap-3',
                gridColsClass,
                FLAT_ROW_HOVER,
                relatedIds.has(String(task.id)) &&
                    'bg-projector-primary-100/80 ring-1 ring-projector-primary-300 ring-inset dark:bg-projector-primary-900/40 dark:ring-projector-primary-700',
            ]"
            @click="openDetail(task)"
            @mouseenter="hoveredId = String(task.id)"
            @mouseleave="hoveredId = null"
        >
            <span
                v-if="hasSubprojects"
                class="truncate text-slate-500 dark:text-slate-400"
                >{{ task.project_name ?? '—' }}</span
            >

            <Select
                :model-value="task.task_status ?? 'todo'"
                @update:model-value="
                    (val) => emit('update-field', task, 'task_status', val)
                "
            >
                <SelectTrigger
                    class="h-auto w-auto justify-start gap-1.5 border-none bg-transparent p-0 shadow-none [&>svg]:hidden"
                    @click.stop
                >
                    <span
                        :class="[
                            'h-1.5 w-1.5 shrink-0 rounded-full',
                            kanbanDotClasses[
                                statusFor(task.task_status)?.color ?? 'slate'
                            ],
                        ]"
                    ></span>
                    <span class="text-slate-500 dark:text-slate-400">{{
                        statusFor(task.task_status)?.label ??
                        task.task_status ??
                        '—'
                    }}</span>
                </SelectTrigger>
                <SelectContent align="start">
                    <SelectItem
                        v-for="column in columns ?? []"
                        :key="column.key"
                        :value="column.key"
                    >
                        <span class="flex items-center gap-1.5">
                            <span
                                :class="[
                                    'h-1.5 w-1.5 shrink-0 rounded-full',
                                    kanbanDotClasses[column.color ?? 'slate'],
                                ]"
                            ></span>
                            {{ column.label }}
                        </span>
                    </SelectItem>
                </SelectContent>
            </Select>

            <span
                class="col-span-2 flex items-start gap-1.5 break-words whitespace-normal text-slate-900 md:col-span-1 dark:text-slate-100"
                :style="depth ? { paddingLeft: `${depth * 16}px` } : undefined"
            >
                <span
                    v-if="showConnections && task.predecessor_id"
                    class="mt-0.5 shrink-0"
                    :title="waitsOnTitle(task)"
                >
                    <CornerDownRight class="h-3.5 w-3.5 text-slate-400" />
                </span>
                <span class="min-w-0">{{ task.name }}</span>
                <span
                    v-if="showConnections && followerCount(task)"
                    class="mt-px shrink-0 rounded bg-slate-100 px-1.5 py-px text-[10px] font-bold text-slate-500 dark:bg-white/10 dark:text-slate-400"
                    :title="`${followerCount(task)} ${followerCount(task) === 1 ? 'task waits' : 'tasks wait'} on this`"
                    >→ {{ followerCount(task) }}</span
                >
            </span>

            <Select
                :model-value="assigneeSelectValue(task)"
                @update:model-value="
                    (val) => emit('update-field', task, 'assignee_id', val)
                "
            >
                <SelectTrigger
                    class="h-auto w-full justify-start border-none bg-transparent p-0 shadow-none [&>svg]:hidden"
                    @click.stop
                >
                    <span class="truncate text-slate-700 dark:text-slate-300">{{
                        assigneeLabel(task)
                    }}</span>
                </SelectTrigger>
                <SelectContent align="start">
                    <SelectItem value="unassigned" class="text-slate-400"
                        >Unassigned</SelectItem
                    >
                    <SelectItem
                        v-for="option in assigneeOptions ?? []"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <div
                v-if="usesTaskStartDates"
                class="contents"
                :title="
                    task.predecessor_id
                        ? 'Starts when the task it waits on ends'
                        : undefined
                "
                @click.stop
            >
                <DateField
                    :model-value="dueDateInputValue(task.start_at)"
                    :disabled="!!task.predecessor_id"
                    :show-icon="false"
                    trigger-class="w-full min-w-0 justify-center text-[13px] text-slate-500 dark:text-slate-400"
                    @update:model-value="
                        (val) => emit('update-field', task, 'start_at', val)
                    "
                />
            </div>

            <div class="contents" @click.stop>
                <DateField
                    v-if="mode !== 'done'"
                    :model-value="dueDateInputValue(task.due_at)"
                    :show-icon="false"
                    trigger-class="w-full min-w-0 justify-center text-[13px] text-slate-500 dark:text-slate-400"
                    @update:model-value="
                        (val) => emit('update-field', task, 'due_at', val)
                    "
                />
                <span
                    v-else
                    class="flex w-full min-w-0 items-center justify-center text-[13px] text-slate-500 dark:text-slate-400"
                    >{{ formatDateOnly(task.status_changed_at) || '—' }}</span
                >
            </div>

            <div v-if="usesExternalDueDates" class="contents" @click.stop>
                <DateField
                    :model-value="dueDateInputValue(task.external_due_at)"
                    :show-icon="false"
                    trigger-class="w-full min-w-0 justify-center text-[13px] text-slate-500 dark:text-slate-400"
                    @update:model-value="
                        (val) =>
                            emit('update-field', task, 'external_due_at', val)
                    "
                />
            </div>
        </div>

        <div
            v-if="tasks.length === 0"
            class="rounded-3xl border-2 border-dashed border-slate-200 py-16 text-center dark:border-slate-800"
        >
            <p class="text-sm font-medium text-slate-400">
                No tasks match those filters.
            </p>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import { show as showDocument } from '@/routes/projects/documents';
import { Link } from '@inertiajs/vue3';
import { ChevronUp, ChevronDown, ChevronsUpDown } from 'lucide-vue-next';
import { PRIORITY_LABELS, priorityDotClasses, kanbanDotClasses } from '@/lib/constants';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';
import { invitationName } from '@/lib/assignees';

export interface TaskReportRow {
    id: string | number;
    project_id: string;
    name: string;
    due_at: string | null;
    external_due_at: string | null;
    priority: string | null;
    task_status: string | null;
    assignee: { id: number; name: string } | null;
    pending_assignee: { id: number; email: string; first_name: string | null; last_name: string | null } | null;
}

export type SortKey = 'status' | 'due_at' | 'external_due_at' | 'name' | 'assignee' | 'priority';
export type SortDir = 'asc' | 'desc';

const props = defineProps<{
    tasks: TaskReportRow[];
    columns?: KanbanColumnDef[];
    usesExternalDueDates?: boolean;
}>();

const emit = defineEmits<{
    (e: 'sort-change', key: SortKey, dir: SortDir): void;
}>();

const statusFor = (statusKey: string | null) => props.columns?.find((c) => c.key === statusKey);

// Real, individually fixed-width grid columns (not a flexed group) — each row's Status
// and due date(s) need to land in the same place, which only a shared column grid
// guarantees; sizing them tight to their actual content (rather than a wide flex group)
// is what keeps Task Name from starting with a gap of dead space after them. "Internal"/
// "External" stack over "Due" in the header (see template) so each due column only needs
// to be as wide as one of those words, not the full "Internal Due" phrase.
const gridColsClass = computed(() =>
    props.usesExternalDueDates
        ? 'md:grid-cols-[110px_90px_90px_1fr_180px_120px]'
        : 'md:grid-cols-[110px_100px_1fr_180px_120px]'
);

const formatDueDate = (value: string | null): string => {
    if (!value) return '—';
    return new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
};

const assigneeLabel = (task: TaskReportRow): string => {
    if (task.assignee) return task.assignee.name;
    if (task.pending_assignee) return invitationName(task.pending_assignee as unknown as OrganizationInvitation);
    return 'Unassigned';
};

// Carries the current (filter-bearing) URL as `from` so the document page's own Back
// button/breadcrumbs (see useDocumentNavigation.ts's getReturnUrl) return here exactly,
// same convention used when opening a document from the tree/kanban views.
const documentUrl = (task: TaskReportRow): string => {
    const baseUrl = showDocument({ project: task.project_id, document: String(task.id) }).url;
    return `${baseUrl}?from=${encodeURIComponent(window.location.href)}`;
};

const PRIORITY_WEIGHT: Record<string, number> = { low: 1, medium: 2, high: 3 };

const sortKey = ref<SortKey>('due_at');
const sortDir = ref<SortDir>('asc');

// The export buttons (see TaskReport.vue) build a fresh document server-side, so they
// can't just re-sort whatever's already on screen the way this table does — they need
// to know the current sort to ask the backend to replicate it.
const toggleSort = (key: SortKey) => {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortDir.value = 'asc';
    }
    emit('sort-change', sortKey.value, sortDir.value);
};

// Nulls always sort last, regardless of direction — an unset due date or priority
// shouldn't jump to the top just because the sort direction flipped to descending.
const compare = (a: string | number | null, b: string | number | null): number => {
    if (a === null && b === null) return 0;
    if (a === null) return 1;
    if (b === null) return -1;
    if (a < b) return -1;
    if (a > b) return 1;
    return 0;
};

const sortValue = (task: TaskReportRow, key: SortKey): string | number | null => {
    switch (key) {
        case 'status':
            return statusFor(task.task_status)?.order ?? null;
        case 'due_at':
            return task.due_at;
        case 'external_due_at':
            return task.external_due_at;
        case 'name':
            return task.name.toLowerCase();
        case 'assignee':
            return assigneeLabel(task).toLowerCase();
        case 'priority':
            return task.priority ? (PRIORITY_WEIGHT[task.priority] ?? null) : null;
    }
};

const sortedTasks = computed(() => {
    const direction = sortDir.value === 'asc' ? 1 : -1;

    return [...props.tasks].sort(
        (a, b) => direction * compare(sortValue(a, sortKey.value), sortValue(b, sortKey.value))
    );
});
</script>

<template>
    <div class="grid gap-0.5">
        <div :class="['hidden md:grid items-center gap-3 px-4 py-2 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400', gridColsClass]">
            <button type="button" class="flex items-center gap-1 hover:text-slate-600 dark:hover:text-slate-300" @click="toggleSort('status')">
                Status
                <ChevronUp v-if="sortKey === 'status' && sortDir === 'asc'" class="h-3 w-3" />
                <ChevronDown v-else-if="sortKey === 'status' && sortDir === 'desc'" class="h-3 w-3" />
                <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
            </button>

            <button
                type="button"
                class="flex flex-col items-center text-center leading-tight hover:text-slate-600 dark:hover:text-slate-300"
                @click="toggleSort('due_at')"
            >
                <span v-if="usesExternalDueDates">Internal</span>
                <span v-else>Due Date</span>
                <span class="flex items-center gap-1">
                    <template v-if="usesExternalDueDates">Due</template>
                    <ChevronUp v-if="sortKey === 'due_at' && sortDir === 'asc'" class="h-3 w-3" />
                    <ChevronDown v-else-if="sortKey === 'due_at' && sortDir === 'desc'" class="h-3 w-3" />
                    <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
                </span>
            </button>

            <button
                v-if="usesExternalDueDates"
                type="button"
                class="flex flex-col items-center text-center leading-tight hover:text-slate-600 dark:hover:text-slate-300"
                @click="toggleSort('external_due_at')"
            >
                <span>External</span>
                <span class="flex items-center gap-1">
                    Due
                    <ChevronUp v-if="sortKey === 'external_due_at' && sortDir === 'asc'" class="h-3 w-3" />
                    <ChevronDown v-else-if="sortKey === 'external_due_at' && sortDir === 'desc'" class="h-3 w-3" />
                    <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
                </span>
            </button>

            <button type="button" class="flex items-center gap-1 hover:text-slate-600 dark:hover:text-slate-300" @click="toggleSort('name')">
                Task Name
                <ChevronUp v-if="sortKey === 'name' && sortDir === 'asc'" class="h-3 w-3" />
                <ChevronDown v-else-if="sortKey === 'name' && sortDir === 'desc'" class="h-3 w-3" />
                <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
            </button>

            <button type="button" class="flex items-center gap-1 hover:text-slate-600 dark:hover:text-slate-300" @click="toggleSort('assignee')">
                Assignee
                <ChevronUp v-if="sortKey === 'assignee' && sortDir === 'asc'" class="h-3 w-3" />
                <ChevronDown v-else-if="sortKey === 'assignee' && sortDir === 'desc'" class="h-3 w-3" />
                <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
            </button>

            <button type="button" class="flex items-center gap-1 hover:text-slate-600 dark:hover:text-slate-300" @click="toggleSort('priority')">
                Priority
                <ChevronUp v-if="sortKey === 'priority' && sortDir === 'asc'" class="h-3 w-3" />
                <ChevronDown v-else-if="sortKey === 'priority' && sortDir === 'desc'" class="h-3 w-3" />
                <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
            </button>
        </div>

        <Link
            v-for="task in sortedTasks"
            :key="task.id"
            :href="documentUrl(task)"
            :class="['grid grid-cols-2 items-center gap-2 md:gap-3 rounded-md px-4 py-3 text-[13px] transition-colors', gridColsClass, FLAT_ROW_HOVER]"
        >
            <span class="flex items-center gap-1.5">
                <span :class="['w-1.5 h-1.5 rounded-full shrink-0', kanbanDotClasses[statusFor(task.task_status)?.color ?? 'slate']]"></span>
                <span class="text-slate-500 dark:text-slate-400">{{ statusFor(task.task_status)?.label ?? task.task_status ?? '—' }}</span>
            </span>

            <span class="text-slate-500 dark:text-slate-400">{{ formatDueDate(task.due_at) }}</span>

            <span v-if="usesExternalDueDates" class="text-slate-500 dark:text-slate-400">{{ formatDueDate(task.external_due_at) }}</span>

            <span class="col-span-2 md:col-span-1 text-slate-900 dark:text-slate-100 truncate">{{ task.name }}</span>

            <span class="truncate text-slate-700 dark:text-slate-300">{{ assigneeLabel(task) }}</span>

            <span class="flex items-center gap-1.5">
                <span :class="['w-1.5 h-1.5 rounded-full shrink-0', priorityDotClasses[task.priority ?? 'low']]"></span>
                <span class="text-slate-500 dark:text-slate-400">{{ task.priority ? PRIORITY_LABELS[task.priority] : '—' }}</span>
            </span>
        </Link>

        <div v-if="tasks.length === 0" class="py-16 text-center rounded-3xl border-2 border-dashed border-slate-200 dark:border-slate-800">
            <p class="text-slate-400 font-medium text-sm">No tasks match those filters.</p>
        </div>
    </div>
</template>

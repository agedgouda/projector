<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MultiSelect, type MultiSelectOption } from '@/components/ui/multi-select';
import { Search, RotateCcw, X } from 'lucide-vue-next';
import { PRIORITY_LABELS } from '@/lib/constants';
import { mergeAssigneeOptions } from '@/lib/assignees';

export interface TaskSearchFilters {
    assignee: string[];
    task_status: string[];
    priority: string[];
    due_from: string;
    due_to: string;
    project_id: string[];
}

const UNASSIGNED = 'unassigned';

const props = defineProps<{
    users?: User[];
    invitations?: OrganizationInvitation[];
    columns?: KanbanColumnDef[];
    loading?: boolean;
    initialFilters?: TaskSearchFilters | null;
    // This project plus its sub-projects, if any — always includes at least the project
    // itself. The filter only renders once there's actually something to choose between.
    projectOptions?: { id: string; name: string }[];
}>();

const emit = defineEmits<{
    (e: 'search', filters: TaskSearchFilters): void;
    // Distinct from 'search' even though both trigger the same unfiltered lookup today — the
    // caller uses this to also forget whatever it's remembering between visits, which a
    // plain search (even one left with every filter on its default) should not do.
    (e: 'reset', filters: TaskSearchFilters): void;
}>();

const assigneeOptions = computed(() => [{ value: UNASSIGNED, label: 'Unassigned' }, ...mergeAssigneeOptions(props.users, props.invitations)]);
const statusOptions = computed(() => (props.columns ?? []).map((column) => ({ value: column.key, label: column.label })));
const priorityOptions = computed(() => Object.entries(PRIORITY_LABELS).map(([value, label]) => ({ value, label })));
const projectSelectOptions = computed(() => (props.projectOptions ?? []).map((option) => ({ value: option.id, label: option.name })));
const showProjectFilter = (props.projectOptions?.length ?? 0) > 1;

const filters = reactive<TaskSearchFilters>({
    assignee: props.initialFilters?.assignee ?? [],
    task_status: props.initialFilters?.task_status ?? [],
    priority: props.initialFilters?.priority ?? [],
    due_from: props.initialFilters?.due_from || '',
    due_to: props.initialFilters?.due_to || '',
    project_id: props.initialFilters?.project_id ?? [],
});

// initialFilters can resolve after this component has already mounted and rendered with
// defaults — the caller doesn't know whether to restore from the URL, from the server, or not
// at all until an async fetch completes (see TaskReport.vue). Once it does resolve to a real
// value, reflect it here rather than leaving the form stuck showing empty defaults.
watch(
    () => props.initialFilters,
    (value) => {
        if (!value) return;
        filters.assignee = value.assignee;
        filters.task_status = value.task_status;
        filters.priority = value.priority;
        filters.due_from = value.due_from;
        filters.due_to = value.due_to;
        filters.project_id = value.project_id;
    },
);

const snapshot = (): TaskSearchFilters => ({
    assignee: [...filters.assignee],
    task_status: [...filters.task_status],
    priority: [...filters.priority],
    due_from: filters.due_from,
    due_to: filters.due_to,
    project_id: [...filters.project_id],
});

const submit = () => {
    emit('search', snapshot());
};

const reset = () => {
    filters.assignee = [];
    filters.task_status = [];
    filters.priority = [];
    filters.due_from = '';
    filters.due_to = '';
    filters.project_id = [];
    emit('reset', snapshot());
};

interface FilterChip {
    id: string;
    label: string;
    remove: () => void;
}

// One chip per individually-selected value (not one per field) — so "Assignee: Alice, Bob"
// shows and removes as two chips, matching how each was chosen and letting either be dropped
// without reopening that field's dropdown to find it again.
const chipsFor = (field: 'assignee' | 'task_status' | 'priority' | 'project_id', options: MultiSelectOption[]): FilterChip[] =>
    options
        .filter((option) => filters[field].includes(option.value))
        .map((option) => ({
            id: `${field}:${option.value}`,
            label: option.label,
            remove: () => {
                filters[field] = filters[field].filter((value) => value !== option.value);
            },
        }));

const activeChips = computed<FilterChip[]>(() => [
    ...chipsFor('project_id', projectSelectOptions.value),
    ...chipsFor('assignee', assigneeOptions.value),
    ...chipsFor('task_status', statusOptions.value),
    ...chipsFor('priority', priorityOptions.value),
    ...(filters.due_from ? [{ id: 'due_from', label: `Due from ${filters.due_from}`, remove: () => { filters.due_from = ''; } }] : []),
    ...(filters.due_to ? [{ id: 'due_to', label: `Due to ${filters.due_to}`, remove: () => { filters.due_to = ''; } }] : []),
]);
</script>

<template>
    <form
        @submit.prevent="submit"
        :class="['grid gap-4 sm:grid-cols-2 lg:items-end', showProjectFilter ? 'lg:grid-cols-6' : 'lg:grid-cols-5']"
    >
        <div v-if="showProjectFilter" class="grid gap-2">
            <Label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Project</Label>
            <MultiSelect v-model="filters.project_id" :options="projectSelectOptions" placeholder="All Projects" search-placeholder="Search projects…" />
        </div>

        <div class="grid gap-2">
            <Label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Assignee</Label>
            <MultiSelect v-model="filters.assignee" :options="assigneeOptions" placeholder="Anyone" search-placeholder="Search assignees…" />
        </div>

        <div class="grid gap-2">
            <Label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Status</Label>
            <MultiSelect v-model="filters.task_status" :options="statusOptions" placeholder="Any Status" search-placeholder="Search statuses…" />
        </div>

        <div class="grid gap-2">
            <Label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Priority</Label>
            <MultiSelect v-model="filters.priority" :options="priorityOptions" placeholder="Any Priority" search-placeholder="Search priorities…" />
        </div>

        <div class="grid gap-2">
            <Label for="report-due-from" class="text-[11px] font-black uppercase tracking-widest text-slate-500">Due From</Label>
            <Input id="report-due-from" v-model="filters.due_from" type="date" class="h-9 text-[13px]" />
        </div>

        <div class="grid gap-2">
            <Label for="report-due-to" class="text-[11px] font-black uppercase tracking-widest text-slate-500">Due To</Label>
            <Input id="report-due-to" v-model="filters.due_to" type="date" class="h-9 text-[13px]" />
        </div>

        <div v-if="activeChips.length" :class="['flex flex-wrap gap-1.5 sm:col-span-2', showProjectFilter ? 'lg:col-span-6' : 'lg:col-span-5']">
            <Badge v-for="chip in activeChips" :key="chip.id" variant="secondary" class="gap-1 pr-1 text-[11px] font-medium">
                {{ chip.label }}
                <button
                    type="button"
                    class="rounded-full p-0.5 hover:bg-slate-300/60 dark:hover:bg-white/10"
                    :aria-label="`Remove ${chip.label}`"
                    @click="chip.remove"
                >
                    <X class="h-3 w-3" />
                </button>
            </Badge>
        </div>

        <div :class="['flex gap-2 sm:col-span-2', showProjectFilter ? 'lg:col-span-6' : 'lg:col-span-5']">
            <Button type="submit" size="sm" :disabled="props.loading">
                <Search class="h-3.5 w-3.5" />
                Search
            </Button>
            <Button type="button" variant="outline" size="sm" :disabled="props.loading" @click="reset">
                <RotateCcw class="h-3.5 w-3.5" />
                Reset
            </Button>
        </div>
    </form>
</template>

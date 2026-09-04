<script setup lang="ts">
import DateField from '@/components/DateField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    MultiSelect,
    type MultiSelectOption,
} from '@/components/ui/multi-select';
import { mergeAssigneeOptions } from '@/lib/assignees';
import { RotateCcw, Search, X } from 'lucide-vue-next';
import { computed, reactive, watch } from 'vue';

export interface TaskSearchFilters {
    assignee: string[];
    task_status: string[];
    priority: string[];
    due_from: string;
    due_to: string;
    project_id: string[];
    category_id: string[];
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
    // The project family's full tag catalog (see Project::familyCategories()) — the filter
    // only renders once there's actually something to choose between.
    categories?: CategoryDef[];
}>();

const emit = defineEmits<{
    (e: 'search', filters: TaskSearchFilters): void;
    // Distinct from 'search' even though both trigger the same unfiltered lookup today — the
    // caller uses this to also forget whatever it's remembering between visits, which a
    // plain search (even one left with every filter on its default) should not do.
    (e: 'reset', filters: TaskSearchFilters): void;
}>();

const assigneeOptions = computed(() => [
    { value: UNASSIGNED, label: 'Unassigned' },
    ...mergeAssigneeOptions(props.users, props.invitations),
]);
const statusOptions = computed(() =>
    (props.columns ?? []).map((column) => ({
        value: column.key,
        label: column.label,
    })),
);
const projectSelectOptions = computed(() =>
    (props.projectOptions ?? []).map((option) => ({
        value: option.id,
        label: option.name,
    })),
);
const showProjectFilter = (props.projectOptions?.length ?? 0) > 1;

// Tailwind's build-time scanner only picks up class names appearing verbatim in source, so
// each state of the one remaining optional filter (Project) is spelled out completely rather
// than assembled from a computed column count.
const formGridColsClass = computed(() =>
    showProjectFilter ? 'lg:grid-cols-5' : 'lg:grid-cols-4',
);
const formColSpanClass = computed(() =>
    showProjectFilter ? 'lg:col-span-5' : 'lg:col-span-4',
);

const filters = reactive<TaskSearchFilters>({
    assignee: props.initialFilters?.assignee ?? [],
    task_status: props.initialFilters?.task_status ?? [],
    priority: props.initialFilters?.priority ?? [],
    due_from: props.initialFilters?.due_from || '',
    due_to: props.initialFilters?.due_to || '',
    project_id: props.initialFilters?.project_id ?? [],
    category_id: props.initialFilters?.category_id ?? [],
});

// initialFilters can resolve after this component has already mounted and rendered with
// defaults — the caller doesn't know whether to restore from the URL, from the server, or not
// at all until an async fetch completes (see TaskReport.vue). Once it does resolve to a real
// value, reflect it here rather than leaving the form stuck showing empty defaults.
watch(
    () => props.initialFilters,
    (value) => {
        if (!value) return;
        // Fall back per-field rather than trusting `value` to have every key — a filter set
        // saved (server-side preferences, see TaskReport.vue's loadPersistedFilters()) before
        // a field like category_id existed won't have it, and an unguarded direct assignment
        // here would leave filters.category_id undefined, crashing chipsFor()'s .includes()
        // the moment this component re-renders.
        filters.assignee = value.assignee ?? [];
        filters.task_status = value.task_status ?? [];
        filters.priority = value.priority ?? [];
        filters.due_from = value.due_from ?? '';
        filters.due_to = value.due_to ?? '';
        filters.project_id = value.project_id ?? [];
        filters.category_id = value.category_id ?? [];
    },
);

const snapshot = (): TaskSearchFilters => ({
    assignee: [...filters.assignee],
    task_status: [...filters.task_status],
    priority: [...filters.priority],
    due_from: filters.due_from,
    due_to: filters.due_to,
    project_id: [...filters.project_id],
    category_id: [...filters.category_id],
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
    filters.category_id = [];
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
const chipsFor = (
    field:
        | 'assignee'
        | 'task_status'
        | 'priority'
        | 'project_id'
        | 'category_id',
    options: MultiSelectOption[],
): FilterChip[] =>
    options
        .filter((option) => filters[field].includes(option.value))
        .map((option) => ({
            id: `${field}:${option.value}`,
            label: option.label,
            remove: () => {
                filters[field] = filters[field].filter(
                    (value) => value !== option.value,
                );
            },
        }));

const activeChips = computed<FilterChip[]>(() => [
    ...chipsFor('project_id', projectSelectOptions.value),
    ...chipsFor('assignee', assigneeOptions.value),
    ...chipsFor('task_status', statusOptions.value),
    ...(filters.due_from
        ? [
              {
                  id: 'due_from',
                  label: `Due from ${filters.due_from}`,
                  remove: () => {
                      filters.due_from = '';
                  },
              },
          ]
        : []),
    ...(filters.due_to
        ? [
              {
                  id: 'due_to',
                  label: `Due to ${filters.due_to}`,
                  remove: () => {
                      filters.due_to = '';
                  },
              },
          ]
        : []),
]);
</script>

<template>
    <form
        @submit.prevent="submit"
        :class="['grid gap-4 sm:grid-cols-2 lg:items-end', formGridColsClass]"
    >
        <div v-if="showProjectFilter" class="grid gap-2">
            <Label
                class="text-[11px] font-black tracking-widest text-slate-500 uppercase"
                >Project</Label
            >
            <MultiSelect
                v-model="filters.project_id"
                :options="projectSelectOptions"
                placeholder="All Projects"
                search-placeholder="Search projects…"
            />
        </div>

        <div class="grid gap-2">
            <Label
                class="text-[11px] font-black tracking-widest text-slate-500 uppercase"
                >Assignee</Label
            >
            <MultiSelect
                v-model="filters.assignee"
                :options="assigneeOptions"
                placeholder="Anyone"
                search-placeholder="Search assignees…"
            />
        </div>

        <div class="grid gap-2">
            <Label
                class="text-[11px] font-black tracking-widest text-slate-500 uppercase"
                >Status</Label
            >
            <MultiSelect
                v-model="filters.task_status"
                :options="statusOptions"
                placeholder="Any Status"
                search-placeholder="Search statuses…"
            />
        </div>

        <div class="grid gap-2">
            <Label
                for="report-due-from"
                class="text-[11px] font-black tracking-widest text-slate-500 uppercase"
                >Due From</Label
            >
            <DateField
                id="report-due-from"
                v-model="filters.due_from"
                placeholder="MM/DD/YYYY"
                icon-class="h-4 w-4 text-muted-foreground"
                trigger-class="h-9 w-full justify-start rounded-md border border-input px-3 text-[13px] shadow-xs hover:bg-accent/50"
            />
        </div>

        <div class="grid gap-2">
            <Label
                for="report-due-to"
                class="text-[11px] font-black tracking-widest text-slate-500 uppercase"
                >Due To</Label
            >
            <DateField
                id="report-due-to"
                v-model="filters.due_to"
                placeholder="MM/DD/YYYY"
                icon-class="h-4 w-4 text-muted-foreground"
                trigger-class="h-9 w-full justify-start rounded-md border border-input px-3 text-[13px] shadow-xs hover:bg-accent/50"
            />
        </div>

        <div
            v-if="activeChips.length"
            :class="['flex flex-wrap gap-1.5 sm:col-span-2', formColSpanClass]"
        >
            <Badge
                v-for="chip in activeChips"
                :key="chip.id"
                variant="secondary"
                class="gap-1 pr-1 text-[11px] font-medium"
            >
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

        <div :class="['flex gap-2 sm:col-span-2', formColSpanClass]">
            <Button type="submit" size="sm" :disabled="props.loading">
                <Search class="h-3.5 w-3.5" />
                Search
            </Button>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="props.loading"
                @click="reset"
            >
                <RotateCcw class="h-3.5 w-3.5" />
                Reset
            </Button>
        </div>
    </form>
</template>

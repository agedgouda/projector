<script setup lang="ts">
import DateField from '@/components/DateField.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { MultiSelect } from '@/components/ui/multi-select';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
} from '@/components/ui/select';
import { mergeAssigneeOptions } from '@/lib/assignees';
import { ChevronDown, RotateCcw, Search } from 'lucide-vue-next';
import { computed, reactive, watch } from 'vue';

export interface TaskSearchFilters {
    assignee: string[];
    task_status: string[];
    priority: string[];
    due_from: string;
    due_to: string;
    mode: 'due' | 'done';
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
    mode: props.initialFilters?.mode ?? 'due',
    project_id: props.initialFilters?.project_id ?? [],
    category_id: props.initialFilters?.category_id ?? [],
});

// "Due To" becomes "Done To" while Done mode is active, so the label stays accurate to
// which date field is actually being filtered — same date fields, different meaning
// depending on mode (see TaskSearchFilters.mode). The first field's own "Due"/"Done" word is
// the mode dropdown itself (see template) rather than a second computed label.
const dateToLabel = computed(() =>
    filters.mode === 'done' ? 'Done To' : 'Due To',
);

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
        // here would leave filters.category_id undefined.
        filters.assignee = value.assignee ?? [];
        filters.task_status = value.task_status ?? [];
        filters.priority = value.priority ?? [];
        filters.due_from = value.due_from ?? '';
        filters.due_to = value.due_to ?? '';
        filters.mode = value.mode === 'done' ? 'done' : 'due';
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
    mode: filters.mode,
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
    filters.mode = 'due';
    filters.project_id = [];
    filters.category_id = [];
    emit('reset', snapshot());
};
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
            <div class="flex items-center gap-1">
                <Select v-model="filters.mode">
                    <SelectTrigger
                        class="h-auto w-auto gap-1 border-none bg-transparent p-0 text-[11px] leading-none font-black tracking-widest text-slate-500 uppercase shadow-none data-[size=default]:h-auto [&_svg]:hidden hover:text-slate-700 dark:hover:text-slate-300"
                    >
                        {{ filters.mode === 'done' ? 'Done' : 'Due' }}
                        <ChevronDown class="!block size-3 text-slate-400" />
                    </SelectTrigger>
                    <SelectContent align="start">
                        <SelectItem value="due">Due</SelectItem>
                        <SelectItem value="done">Done</SelectItem>
                    </SelectContent>
                </Select>
                <Label
                    for="report-due-from"
                    class="text-[11px] font-black tracking-widest text-slate-500 uppercase"
                    >From</Label
                >
            </div>
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
                >{{ dateToLabel }}</Label
            >
            <DateField
                id="report-due-to"
                v-model="filters.due_to"
                placeholder="MM/DD/YYYY"
                icon-class="h-4 w-4 text-muted-foreground"
                trigger-class="h-9 w-full justify-start rounded-md border border-input px-3 text-[13px] shadow-xs hover:bg-accent/50"
            />
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

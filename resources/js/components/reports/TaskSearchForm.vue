<script setup lang="ts">
import { computed, reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MultiSelect } from '@/components/ui/multi-select';
import { Search, RotateCcw } from 'lucide-vue-next';
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

const submit = () => {
    emit('search', {
        assignee: [...filters.assignee],
        task_status: [...filters.task_status],
        priority: [...filters.priority],
        due_from: filters.due_from,
        due_to: filters.due_to,
        project_id: [...filters.project_id],
    });
};

const reset = () => {
    filters.assignee = [];
    filters.task_status = [];
    filters.priority = [];
    filters.due_from = '';
    filters.due_to = '';
    filters.project_id = [];
    submit();
};
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

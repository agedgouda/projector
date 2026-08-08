<script setup lang="ts">
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Search, RotateCcw } from 'lucide-vue-next';
import { PRIORITY_LABELS } from '@/lib/constants';
import { mergeAssigneeOptions } from '@/lib/assignees';

export interface TaskSearchFilters {
    assignee: string;
    task_status: string;
    priority: string;
    due_from: string;
    due_to: string;
}

const ANY = 'all';
const UNASSIGNED = 'unassigned';

const props = defineProps<{
    users?: User[];
    invitations?: OrganizationInvitation[];
    columns?: KanbanColumnDef[];
    loading?: boolean;
    initialFilters?: TaskSearchFilters | null;
}>();

const emit = defineEmits<{
    (e: 'search', filters: TaskSearchFilters): void;
}>();

const assigneeOptions = mergeAssigneeOptions(props.users, props.invitations);

const filters = reactive<TaskSearchFilters>({
    assignee: props.initialFilters?.assignee || ANY,
    task_status: props.initialFilters?.task_status || ANY,
    priority: props.initialFilters?.priority || ANY,
    due_from: props.initialFilters?.due_from || '',
    due_to: props.initialFilters?.due_to || '',
});

const submit = () => {
    emit('search', {
        assignee: filters.assignee === ANY ? '' : filters.assignee,
        task_status: filters.task_status === ANY ? '' : filters.task_status,
        priority: filters.priority === ANY ? '' : filters.priority,
        due_from: filters.due_from,
        due_to: filters.due_to,
    });
};

const reset = () => {
    filters.assignee = ANY;
    filters.task_status = ANY;
    filters.priority = ANY;
    filters.due_from = '';
    filters.due_to = '';
    submit();
};
</script>

<template>
    <form @submit.prevent="submit" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
        <div class="grid gap-2">
            <Label for="report-assignee" class="text-[11px] font-black uppercase tracking-widest text-slate-500">Assignee</Label>
            <Select v-model="filters.assignee">
                <SelectTrigger id="report-assignee" class="h-9 text-[13px]">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ANY">Anyone</SelectItem>
                    <SelectItem :value="UNASSIGNED">Unassigned</SelectItem>
                    <SelectItem v-for="option in assigneeOptions" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="grid gap-2">
            <Label for="report-status" class="text-[11px] font-black uppercase tracking-widest text-slate-500">Status</Label>
            <Select v-model="filters.task_status">
                <SelectTrigger id="report-status" class="h-9 text-[13px]">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ANY">Any Status</SelectItem>
                    <SelectItem v-for="column in props.columns ?? []" :key="column.key" :value="column.key">
                        {{ column.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="grid gap-2">
            <Label for="report-priority" class="text-[11px] font-black uppercase tracking-widest text-slate-500">Priority</Label>
            <Select v-model="filters.priority">
                <SelectTrigger id="report-priority" class="h-9 text-[13px]">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ANY">Any Priority</SelectItem>
                    <SelectItem v-for="(label, key) in PRIORITY_LABELS" :key="key" :value="key">
                        {{ label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="grid gap-2">
            <Label for="report-due-from" class="text-[11px] font-black uppercase tracking-widest text-slate-500">Due From</Label>
            <Input id="report-due-from" v-model="filters.due_from" type="date" class="h-9 text-[13px]" />
        </div>

        <div class="grid gap-2">
            <Label for="report-due-to" class="text-[11px] font-black uppercase tracking-widest text-slate-500">Due To</Label>
            <Input id="report-due-to" v-model="filters.due_to" type="date" class="h-9 text-[13px]" />
        </div>

        <div class="flex gap-2 sm:col-span-2 lg:col-span-5">
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

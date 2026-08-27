<script setup lang="ts">
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
} from '@/components/ui/select';
import { Calendar } from 'lucide-vue-next';

const props = defineProps<{
    doc: ProjectDocument;
    columns: KanbanColumnDef[];
    usesExternalDueDates?: boolean;
    readOnly?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update', field: string, value: any): void;
}>();

const currentColumn = computed(() => props.columns.find((c) => c.key === (props.doc.task_status ?? 'todo')));

// Only one due-date field is shown here (unlike the full sidebar, which shows both internal
// and external side by side) — a dense row only has room for one, so it edits whichever the
// org actually uses.
const dueField = computed(() => (props.usesExternalDueDates ? 'external_due_at' : 'due_at'));
const dueValue = computed(() => {
    const raw = props.usesExternalDueDates ? props.doc.external_due_at : props.doc.due_at;
    return raw ? raw.slice(0, 10) : '';
});

const handleUpdate = (field: string, value: any) => {
    let finalValue = value;
    if ((field === 'due_at' || field === 'external_due_at') && value === '') finalValue = null;
    emit('update', field, finalValue);
};
</script>

<template>
    <div class="flex shrink-0 items-center gap-5">
        <!-- Status — fixed-width and right-justified so Due (next column) doesn't shift
             depending on label length ("TO DO" vs "IN PROGRESS"). No dot here (see
             PriorityDot.vue, rendered separately at the start of the row, before the type
             icon — priority is the only per-row color indicator now). No @click.stop needed
             in here: the caller (TaskRowContent.vue) puts it on its whole <TaskRowFields>
             usage, since the row it renders inside navigates on any click. -->
        <div v-if="readOnly" class="w-28 truncate text-right text-[9px] font-bold uppercase tracking-wider text-slate-900 dark:text-slate-100">
            {{ currentColumn?.label ?? doc.task_status ?? 'todo' }}
        </div>
        <div v-else class="w-28">
            <Select :model-value="doc.task_status ?? 'todo'" @update:model-value="(val) => handleUpdate('task_status', val)">
                <SelectTrigger class="h-auto w-28 justify-end border-none bg-transparent p-0 shadow-none [&>svg]:hidden">
                    <span class="truncate text-[9px] font-bold uppercase tracking-wider text-slate-900 dark:text-slate-100">{{ currentColumn?.label ?? doc.task_status ?? 'todo' }}</span>
                </SelectTrigger>
                <SelectContent align="end">
                    <SelectItem v-for="column in columns" :key="column.key" :value="column.key" class="text-[10px] font-black uppercase">
                        {{ column.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <!-- Due date — fixed width, right-justified read-only, a dash when empty. w-28 (not
             w-24) because the native date input's own internal "MM/DD/YYYY" rendering plus its
             calendar-picker icon needs ~111px alongside the Calendar icon + gap here — narrower
             and the input either overflows the row (pre-min-w-0) or clips its own last
             character (post-min-w-0 without the extra width). -->
        <div v-if="readOnly" class="flex w-28 items-center justify-end gap-1">
            <span class="text-[9px] font-bold uppercase tracking-wider text-slate-900 dark:text-slate-100">{{ dueValue || '--' }}</span>
            <Calendar class="h-3 w-3 shrink-0 text-slate-400" />
        </div>
        <div v-else class="flex w-28 items-center gap-1">
            <Calendar class="h-3 w-3 shrink-0 text-slate-400" />
            <input
                type="date"
                :value="dueValue"
                @change="(e) => handleUpdate(dueField, (e.target as HTMLInputElement).value)"
                class="w-full min-w-0 cursor-pointer border-none bg-transparent p-0 text-[9px] font-bold uppercase tracking-wider text-slate-900 dark:text-slate-100 focus:ring-0 [&::-webkit-calendar-picker-indicator]:h-4 [&::-webkit-calendar-picker-indicator]:w-4 [&::-webkit-calendar-picker-indicator]:cursor-pointer"
            />
        </div>
    </div>
</template>

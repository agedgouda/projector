<script setup lang="ts">
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
} from '@/components/ui/select';
import { getAvatarAppearance } from '@/lib/kanban-theme';
import { invitationName, type AssigneeOption } from '@/lib/assignees';

const props = defineProps<{
    doc: ProjectDocument;
    assigneeOptions: AssigneeOption[];
    readOnly?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update', value: string | null): void;
}>();

const assigneeValue = computed(() => {
    if (props.doc.pending_assignee_invitation_id) {
        return `inv:${props.doc.pending_assignee_invitation_id}`;
    }
    return props.doc.assignee_id?.toString() ?? 'unassigned';
});

const assigneeLabel = computed(() => {
    if (props.doc.assignee) return props.doc.assignee.name;
    if (props.doc.pending_assignee) return invitationName(props.doc.pending_assignee);
    return 'Unassigned';
});

const assigneeInitials = computed(() => {
    const parts = assigneeLabel.value.trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
});

const assigneeColorId = computed(() => props.doc.assignee?.id ?? props.doc.pending_assignee?.id ?? 0);
const isPendingAssignee = computed(() => !props.doc.assignee && !!props.doc.pending_assignee);
const hasAssignee = computed(() => !!props.doc.assignee || !!props.doc.pending_assignee);

const handleUpdate = (value: string) => emit('update', value === 'unassigned' ? null : value);
</script>

<template>
    <div
        v-if="readOnly"
        :class="[
            'flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-[9px] font-black uppercase',
            getAvatarAppearance(assigneeColorId),
            { 'grayscale opacity-50': isPendingAssignee },
        ]"
        :title="assigneeLabel"
    >
        {{ assigneeInitials }}
    </div>
    <div v-else>
        <Select :model-value="assigneeValue" @update:model-value="(val) => handleUpdate(val as string)">
            <SelectTrigger class="h-auto w-auto gap-0 border-none bg-transparent p-0 shadow-none [&>svg]:hidden" :title="assigneeLabel">
                <div
                    v-if="hasAssignee"
                    :class="[
                        'flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-[9px] font-black uppercase',
                        getAvatarAppearance(assigneeColorId),
                        { 'grayscale opacity-50': isPendingAssignee },
                    ]"
                >
                    {{ assigneeInitials }}
                </div>
                <div v-else class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-dashed border-slate-400 dark:border-slate-500">
                    <span class="text-[8px] font-bold text-slate-300">--</span>
                </div>
            </SelectTrigger>
            <SelectContent align="end">
                <SelectItem value="unassigned" class="text-[10px] font-bold text-slate-400 uppercase">Unassigned</SelectItem>
                <SelectItem v-for="option in assigneeOptions" :key="option.value" :value="option.value" class="text-[10px] font-bold uppercase">
                    {{ option.label }}
                </SelectItem>
            </SelectContent>
        </Select>
    </div>
</template>

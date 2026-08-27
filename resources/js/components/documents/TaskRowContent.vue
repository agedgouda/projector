<script setup lang="ts">
import AssigneeAvatar from '@/components/documents/AssigneeAvatar.vue';
import PriorityDot from '@/components/documents/PriorityDot.vue';
import TaskRowFields from '@/components/documents/TaskRowFields.vue';
import type { AssigneeOption } from '@/lib/assignees';
import { CheckSquare, RefreshCw } from 'lucide-vue-next';
import { computed } from 'vue';

// The ENTIRE visual/interactive content of a task row — icon through assignee — lives here
// and only here. TraceabilityRow.vue (the Documentation tree) and DocumentContent.vue's
// "Generated Tasks" list both render a task row by calling this one component; neither
// hand-rolls any of it. That's deliberate: the two were previously kept in sync by manually
// mirroring changes across two separate templates, which silently drifted apart more than
// once. A future change to what a task row shows only needs to happen here.
//
// Clicking anywhere in the row navigates to the document — the caller owns that (a plain
// `@click` on its own row element, since this component's own root is `display:contents` and
// has no box of its own), guarding each interactive control below with `.stop` so priority/
// assignee/status/due-date edits don't also trigger navigation.
const props = defineProps<{
    doc: ProjectDocument;
    columns: KanbanColumnDef[];
    assigneeOptions: AssigneeOption[];
    usesExternalDueDates?: boolean;
    readOnly?: boolean;
    // Root-level tree rows render bold; everywhere else (nested tree rows, the flat
    // "Generated Tasks" list) is medium-weight.
    bold?: boolean;
    // Extra classes for the trailing status/due cluster — TraceabilityRow.vue uses this for
    // its responsive hide-below-md behavior and its own left/right margins, which only make
    // sense in the tree's narrower layout; DocumentContent.vue doesn't need either.
    fieldsClass?: string;
}>();

const emit = defineEmits<{
    (e: 'update', field: string, value: any): void;
}>();

const isProcessing = computed(
    () => !!(props.doc as any).currentStatus || props.doc.processed_at === null,
);

const handleUpdate = (field: string, value: any) =>
    emit('update', field, value);
</script>

<template>
    <div class="contents">
        <PriorityDot
            :priority="doc.priority"
            :read-only="readOnly"
            class="ml-1"
            @click.stop
            @update="(val) => handleUpdate('priority', val)"
        />

        <CheckSquare class="h-3.5 w-3.5 shrink-0 text-slate-400" />

        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <span
                class="text-[13px] transition-colors"
                :class="[
                    bold ? 'font-bold' : 'font-medium',
                    isProcessing
                        ? 'text-slate-400 dark:text-slate-500'
                        : 'text-slate-900 dark:text-slate-100',
                    // Long titles wrap onto multiple lines (growing the row) instead of
                    // truncating with an ellipsis — short titles keep single-line truncate.
                    doc.name.length > 60
                        ? 'break-words whitespace-normal'
                        : 'truncate',
                ]"
            >
                {{ doc.name }}
            </span>

            <!-- Always shown here (not just when assigned) — an unassigned task renders
                 AssigneeAvatar's own dashed-placeholder state, which is itself the picker, so
                 there's still a click target to assign someone even with nobody assigned yet.
                 The only assignee control on the row; TaskRowFields.vue no longer has one. -->
            <AssigneeAvatar
                :doc="doc"
                :assignee-options="assigneeOptions"
                :read-only="readOnly"
                @click.stop
                @update="(val) => handleUpdate('assignee_id', val)"
            />

            <span
                v-if="isProcessing"
                class="flex shrink-0 items-center gap-1.5 text-[10px] text-projector-primary-500"
            >
                <RefreshCw class="h-3 w-3 animate-spin" />
                <span class="animate-pulse">{{
                    (doc as any).currentStatus || 'Processing...'
                }}</span>
            </span>
        </div>

        <TaskRowFields
            :class="fieldsClass"
            :doc="doc"
            :columns="columns"
            :uses-external-due-dates="usesExternalDueDates"
            :read-only="readOnly"
            @click.stop
            @update="(field, val) => handleUpdate(field, val)"
        />
    </div>
</template>

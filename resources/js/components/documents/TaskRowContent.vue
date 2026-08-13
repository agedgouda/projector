<script setup lang="ts">
import { computed } from 'vue';
import { CheckSquare, RefreshCw, Eye } from 'lucide-vue-next';
import { PopoverTrigger } from '@/components/ui/popover';
import TaskRowFields from '@/components/documents/TaskRowFields.vue';
import PriorityDot from '@/components/documents/PriorityDot.vue';
import AssigneeAvatar from '@/components/documents/AssigneeAvatar.vue';
import type { AssigneeOption } from '@/lib/assignees';

// The ENTIRE visual/interactive content of a task row — icon through assignee — lives here
// and only here. TraceabilityRow.vue (the Documentation tree) and DocumentContent.vue's
// "Generated Tasks" list both render a task row by calling this one component; neither
// hand-rolls any of it. That's deliberate: the two were previously kept in sync by manually
// mirroring changes across two separate templates, which silently drifted apart more than
// once. A future change to what a task row shows only needs to happen here.
//
// The preview popover is the one exception: this renders only the trigger (the eye icon).
// The <Popover>/<PopoverAnchor>/<PopoverContent> themselves are owned by the caller (see
// TraceabilityRow.vue/DocumentContent.vue) because the popover needs to be sized to the row's
// own width, and only the caller has a real DOM element for "the row" — this component's own
// root is `display:contents` precisely so it has no box of its own. reka-ui's Popover pieces
// work via provide/inject, not DOM nesting, so a Trigger rendered in a child component still
// connects to a Popover rendered by its parent. What the popover actually shows is still
// unified in one place: DocumentPreviewCard.vue, which every caller renders identically.
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

const isProcessing = computed(() => !!(props.doc as any).currentStatus || props.doc.processed_at === null);

const handleUpdate = (field: string, value: any) => emit('update', field, value);
</script>

<template>
    <div class="contents">
        <PriorityDot
            :priority="doc.priority"
            :read-only="readOnly"
            @update="(val) => handleUpdate('priority', val)"
        />

        <CheckSquare class="h-3.5 w-3.5 shrink-0 text-slate-400" />

        <div class="flex flex-1 items-center gap-1.5 min-w-0">
            <span
                class="text-[13px]"
                :class="[
                    bold ? 'font-bold' : 'font-medium',
                    isProcessing ? 'text-slate-400 dark:text-slate-500' : 'text-slate-900 dark:text-slate-100',
                    // Long titles wrap onto multiple lines (growing the row) instead of
                    // truncating with an ellipsis — short titles keep single-line truncate.
                    doc.name.length > 60 ? 'whitespace-normal break-words' : 'truncate'
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
                @update="(val) => handleUpdate('assignee_id', val)"
            />

            <span v-if="isProcessing" class="flex items-center gap-1.5 text-[10px] text-projector-primary-500 shrink-0">
                <RefreshCw class="h-3 w-3 animate-spin" />
                <span class="animate-pulse">{{ (doc as any).currentStatus || 'Processing...' }}</span>
            </span>
        </div>

        <!-- Lets a caller inject something between the title and the fields (TraceabilityRow.vue
             uses this for its Reprocess button, which only the tree wires up) without
             duplicating anything else here — DocumentContent.vue's Generated Tasks list has no
             equivalent action, so it just doesn't use the slot. -->
        <slot name="actions" />

        <TaskRowFields
            :class="fieldsClass"
            :doc="doc"
            :columns="columns"
            :uses-external-due-dates="usesExternalDueDates"
            :read-only="readOnly"
            @update="(field, val) => handleUpdate(field, val)"
        />

        <PopoverTrigger as-child>
            <!-- Always visible (not hover-revealed) — an earlier version only showed this on
                 row hover, which the user said made it hard to discover it exists at all. Also
                 always click-to-open, not hover-to-open, since a still-earlier version that
                 opened on hovering the title read as intrusive. data-[state=open] comes from
                 reka-ui automatically, no local open-state tracking needed here. -->
            <button
                type="button"
                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 data-[state=open]:bg-slate-100 data-[state=open]:text-slate-600 dark:text-slate-500 dark:hover:bg-slate-800 dark:hover:text-slate-300 dark:data-[state=open]:bg-slate-800 dark:data-[state=open]:text-slate-300"
                title="Preview"
            >
                <Eye class="h-3.5 w-3.5" />
            </button>
        </PopoverTrigger>
    </div>
</template>

<script setup lang="ts">
import AssigneeAvatar from '@/components/documents/AssigneeAvatar.vue';
import PriorityDot from '@/components/documents/PriorityDot.vue';
import TaskRowFields from '@/components/documents/TaskRowFields.vue';
import type { AssigneeOption } from '@/lib/assignees';
import { CheckSquare, Eye, RefreshCw } from 'lucide-vue-next';
import { computed } from 'vue';

// The ENTIRE visual/interactive content of a task row — icon through assignee — lives here
// and only here. TraceabilityRow.vue (the Documentation tree) and DocumentContent.vue's
// "Generated Tasks" list both render a task row by calling this one component; neither
// hand-rolls any of it. That's deliberate: the two were previously kept in sync by manually
// mirroring changes across two separate templates, which silently drifted apart more than
// once. A future change to what a task row shows only needs to happen here.
//
// The preview popover is the one exception: this only emits `hover-preview`/`navigate` from
// the title and eye icon. The <Popover>/<PopoverAnchor>/<PopoverContent> themselves are owned
// by the caller (see TraceabilityRow.vue/DocumentContent.vue) because the popover needs to be
// sized to the row's own width, and only the caller has a real DOM element for "the row" — this
// component's own root is `display:contents` precisely so it has no box of its own. What the
// popover actually shows is still unified in one place: DocumentPreviewCard.vue, which every
// caller renders identically.
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
    (e: 'navigate'): void;
    (e: 'hover-preview', hovering: boolean): void;
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
            @update="(val) => handleUpdate('priority', val)"
        />

        <CheckSquare class="h-3.5 w-3.5 shrink-0 text-slate-400" />

        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <span
                class="cursor-pointer text-[13px] transition-colors hover:text-projector-primary-600 dark:hover:text-projector-primary-400"
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
                @mouseenter="emit('hover-preview', true)"
                @mouseleave="emit('hover-preview', false)"
                @click="emit('navigate')"
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

        <!-- Always visible (not hover-revealed) — an earlier version only showed this on row
             hover, which the user said made it hard to discover it exists at all. Hovering the
             icon itself (or the title above) opens the preview popover; clicking either one
             navigates straight to the document instead of toggling the popover, so this is a
             plain button rather than a PopoverTrigger — the caller drives the popover's open
             state off `hover-preview`. -->
        <button
            type="button"
            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-slate-400 transition-colors hover:bg-slate-100 hover:text-projector-primary-600 dark:text-slate-500 dark:hover:bg-slate-800 dark:hover:text-projector-primary-400"
            title="Open"
            @mouseenter="emit('hover-preview', true)"
            @mouseleave="emit('hover-preview', false)"
            @click="emit('navigate')"
        >
            <Eye class="h-3.5 w-3.5" />
        </button>
    </div>
</template>

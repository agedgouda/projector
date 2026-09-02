<script setup lang="ts">
import TaskRowContent from '@/components/documents/TaskRowContent.vue';
import { useDocumentActions } from '@/composables/useDocumentActions';
import type { AssigneeOption } from '@/lib/assignees';
import {
    FLAT_ROW_ACCENT_BAR,
    FLAT_ROW_HOVER,
    FLAT_ROW_SELECTED,
} from '@/lib/flat-ui';
import { formatDateOnly } from '@/lib/utils';
import {
    Calendar as CalendarIcon,
    CheckSquare,
    ChevronRight,
    FileText,
    Folder,
    FolderOpen,
    RefreshCw,
} from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    item: any;
    level: number;
    index?: number;
    expandedRootIds: Set<string | number>;
    isTaskType: (type: string) => boolean;
    selectedSheetId: string | number | null;
    assigneeOptions: AssigneeOption[];
    usesExternalDueDates?: boolean;
    isReadOnly?: boolean;
    columns: KanbanColumnDef[];
}>();

const emit = defineEmits<{
    (e: 'toggleRoot', id: string | number): void;
    (e: 'onDeleteRequested', item: any): void;
    (e: 'updateTask', id: string | number, field: string, value: any): void;
}>();

const isTreeExpanded = computed(
    () =>
        props.expandedRootIds instanceof Set &&
        props.expandedRootIds.has(props.item.id),
);
const isSelected = computed(() => props.selectedSheetId === props.item.id);
const isGroup = computed(() => !!props.item.isTypeGroup);
const isTask = computed(() => props.isTaskType(props.item.type));

// Non-task rows show no date info anywhere else (see below) — shown generically off
// due_at/start_at rather than gated to a specific type, so any non-task document with dates
// (e.g. an Event) picks this up without another hardcoded type check.
const formatRowDate = formatDateOnly;
const nonTaskDateRange = computed(() => {
    if (isTask.value) return null;
    const start = props.item.start_at
        ? formatRowDate(props.item.start_at)
        : null;
    const end = props.item.due_at ? formatRowDate(props.item.due_at) : null;
    if (!start && !end) return null;
    if (start && end && start !== end) return `${start} – ${end}`;
    return end ?? start;
});
const isProcessing = computed(
    () => !!props.item.currentStatus || props.item.processed_at === null,
);

const { navigateToDetails } = useDocumentActions({
    project: { id: props.item.project_id } as any,
});

const goToDetails = () =>
    navigateToDetails(props.item.project_id, props.item.id);

// A folder row has nothing to navigate to — clicking it (not just its chevron) toggles it,
// same as clicking anywhere on a document row navigates to that document.
const handleRowClick = () => {
    if (isGroup.value) {
        emit('toggleRoot', props.item.id);
    } else {
        goToDetails();
    }
};
</script>

<template>
    <div class="flex flex-col">
        <div
            class="group relative flex min-h-9 cursor-pointer items-center gap-2.5 rounded-md pr-2 transition-colors"
            :class="
                isSelected
                    ? FLAT_ROW_SELECTED
                    : [
                          FLAT_ROW_HOVER,
                          // Folder rows are already visually distinct (icon, bold, count) —
                          // stacking the children's own zebra stripe on top of them too just
                          // clashes whenever a striped folder lands next to a striped child.
                          // A border instead separates each folder section from what follows
                          // (its own children, or the next folder once collapsed) without
                          // competing with that striping.
                          !isGroup && index !== undefined && index % 2 === 1
                              ? 'bg-projector-primary-100/70 dark:bg-projector-primary-950/25'
                              : '',
                          isGroup
                              ? 'border-b border-slate-200 dark:border-slate-800'
                              : '',
                      ]
            "
            @click="handleRowClick"
        >
            <div v-if="isSelected" :class="FLAT_ROW_ACCENT_BAR"></div>

            <button
                v-if="item.children?.length"
                type="button"
                class="flex h-5 w-5 shrink-0 items-center justify-center rounded text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 dark:hover:text-slate-300"
                @click.stop="emit('toggleRoot', item.id)"
            >
                <ChevronRight
                    class="h-3.5 w-3.5 transition-transform duration-200"
                    :class="{ 'rotate-90': isTreeExpanded }"
                />
            </button>
            <span v-else class="h-5 w-5 shrink-0"></span>

            <!-- Task rows delegate their entire icon-through-assignee content to
                 TaskRowContent.vue — the same component DocumentContent.vue's "Generated
                 Tasks" list uses, so the two can't drift apart the way two hand-mirrored
                 templates did. Non-task rows (Notes, Action Items, etc.) have no equivalent
                 elsewhere to stay in sync with, so they keep their own rendering below. -->
            <TaskRowContent
                v-if="isTask"
                :doc="item"
                :columns="columns"
                :assignee-options="assigneeOptions"
                :uses-external-due-dates="usesExternalDueDates"
                :read-only="isReadOnly"
                :bold="level === 0"
                fields-class="hidden md:flex ml-2 mr-[10px]"
                @update="
                    (field, val) => emit('updateTask', item.id, field, val)
                "
            />

            <template v-else>
                <div
                    class="flex h-4 w-4 shrink-0 items-center justify-center"
                    :class="
                        isSelected
                            ? 'text-projector-primary-600'
                            : 'text-slate-400'
                    "
                >
                    <template v-if="isGroup">
                        <FolderOpen v-if="isTreeExpanded" class="h-3.5 w-3.5" />
                        <Folder v-else class="h-3.5 w-3.5" />
                    </template>
                    <FileText v-else class="h-3.5 w-3.5" />
                </div>

                <div class="flex min-w-0 flex-1 items-center gap-1.5">
                    <span
                        class="text-[13px] transition-colors"
                        :class="[
                            level === 0 ? 'font-bold' : 'font-medium',
                            isProcessing
                                ? 'text-slate-400 dark:text-slate-500'
                                : 'text-slate-900 dark:text-slate-100',
                            // Long titles are allowed to wrap onto multiple lines (growing the row)
                            // when the window gets too narrow, rather than truncating with an
                            // ellipsis — short titles keep the single-line truncate behavior always.
                            item.name.length > 60
                                ? 'break-words whitespace-normal'
                                : 'truncate',
                        ]"
                    >
                        {{ item.name
                        }}<template v-if="isGroup"> ({{ item.children.length }})</template>
                    </span>

                    <span
                        v-if="nonTaskDateRange"
                        class="flex shrink-0 items-center gap-1 text-[9px] font-black text-slate-400 dark:text-slate-500"
                    >
                        <CalendarIcon class="h-2.5 w-2.5" />
                        {{ nonTaskDateRange }}
                    </span>

                    <span
                        v-if="item.tasks?.length"
                        class="flex shrink-0 items-center gap-1 text-[9px] font-black text-emerald-600 dark:text-emerald-400"
                    >
                        <CheckSquare class="h-2.5 w-2.5" />
                        {{ item.tasks.length }}
                    </span>

                    <span
                        v-if="isProcessing"
                        class="flex shrink-0 items-center gap-1.5 text-[10px] text-projector-primary-500"
                    >
                        <RefreshCw class="h-3 w-3 animate-spin" />
                        <span class="animate-pulse">{{
                            item.currentStatus || 'Processing...'
                        }}</span>
                    </span>

                    <span
                        v-if="!isGroup"
                        class="ml-auto flex shrink-0 items-center gap-2.5 text-[9px] font-black text-slate-400 dark:text-slate-500"
                    >
                        <span>Created {{ formatRowDate(item.created_at) }}</span>
                        <span>Updated {{ formatRowDate(item.updated_at) }}</span>
                    </span>
                </div>
            </template>
        </div>

        <div
            v-if="isTreeExpanded && item.children?.length"
            class="relative pl-7"
        >
            <div
                class="absolute top-0 bottom-0 left-[14px] w-px bg-slate-200 dark:bg-slate-800"
            ></div>
            <TraceabilityRow
                v-for="(child, childIndex) in item.children"
                :key="'doc-' + child.id"
                :item="child"
                :index="childIndex"
                :level="level + 1"
                :expanded-root-ids="expandedRootIds"
                :is-task-type="isTaskType"
                :selected-sheet-id="selectedSheetId"
                :assignee-options="assigneeOptions"
                :uses-external-due-dates="usesExternalDueDates"
                :is-read-only="isReadOnly"
                :columns="columns"
                @toggle-root="(id) => emit('toggleRoot', id)"
                @on-delete-requested="(i) => emit('onDeleteRequested', i)"
                @update-task="
                    (id, field, val) => emit('updateTask', id, field, val)
                "
            />
        </div>
    </div>
</template>

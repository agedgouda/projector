<script setup lang="ts">
import { computed, ref } from 'vue';
import { ChevronRight, FileText, Folder, CheckSquare, Sparkles, RefreshCw, GitBranch, Eye } from 'lucide-vue-next';
import { useDocumentActions } from '@/composables/useDocumentActions';
import { INTAKE_KEY } from '@/composables/useWorkflow';
import { FLAT_ROW_SELECTED, FLAT_ROW_ACCENT_BAR } from '@/lib/flat-ui';
import { Popover, PopoverAnchor, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import TransformPicker from '@/components/documents/TransformPicker.vue';
import TaskRowContent from '@/components/documents/TaskRowContent.vue';
import DocumentPreviewCard from '@/components/documents/DocumentPreviewCard.vue';
import type { AssigneeOption } from '@/lib/assignees';

const props = defineProps<{
    item: any;
    level: number;
    index?: number;
    reprocessableTypes: Set<string>;
    aiProcessedParentIds: Set<string>;
    expandedRootIds: Set<string | number>;
    getDocLabel: (type: string) => string;
    isTaskType: (type: string) => boolean;
    selectedSheetId: string | number | null;
    getLeadUser: (doc: any) => any;
    assigneeOptions: AssigneeOption[];
    usesExternalDueDates?: boolean;
    isReadOnly?: boolean;
    columns: KanbanColumnDef[];
}>();

const emit = defineEmits<{
    (e: 'toggleRoot', id: string | number): void;
    (e: 'handleReprocess', id: string ): void;
    (e: 'handleTransition', id: string, payload: { toKey?: string; aiTemplateId: number; singleOutput?: boolean; projectTypeId?: string }): void;
    (e: 'onDeleteRequested', item: any): void;
    (e: 'updateTask', id: string | number, field: string, value: any): void;
}>();

const isTreeExpanded = computed(() => props.expandedRootIds instanceof Set && props.expandedRootIds.has(props.item.id));
const isSelected = computed(() => props.selectedSheetId === props.item.id);
const isTask = computed(() => props.isTaskType(props.item.type));
const isNotes = computed(() => props.item.type === INTAKE_KEY);
const isProcessing = computed(() => !!props.item.currentStatus || props.item.processed_at === null);

// Use the helper to get the lead user for this row
const leadUser = computed(() => props.getLeadUser(props.item));
const { navigateToDetails } = useDocumentActions({
    project: { id: props.item.project_id } as any
});

const isLocked = computed(() => !!props.item.locked_project_type_id);
// A locked document only has something to (re)process if its locked protocol
// still defines a next workflow step for its own type — otherwise it's a
// terminal deliverable and reprocessing would never produce a new child.
// A document with no single, unambiguous next step of its own (see useWorkflow's INTAKE_KEY
// note) is still reprocessable once it's been run through a Transform at least once — reprocess
// then means "re-run that exact same transformation again" (see Document::lastAiTemplate()).
const isReprocessable = computed(() =>
    props.reprocessableTypes.has(props.item.type)
    || (isLocked.value && !!props.item.locked_next_workflow_step_exists)
    || !!props.item.last_ai_template_id
);
const processButtonLabel = computed(() => props.aiProcessedParentIds.has(props.item.id) ? 'Reprocess' : 'Process');

const isTransformOpen = ref(false);
const handleRunTransform = (payload: { toKey?: string; aiTemplateId: number; singleOutput?: boolean; projectTypeId?: string }) => {
    isTransformOpen.value = false;
    emit('handleTransition', props.item.id, payload);
};

const goToDetails = () => navigateToDetails(props.item.project_id, props.item.id);

// Click-triggered preview via an always-visible icon at the far right of the row. The
// <Popover> wraps the whole row (via PopoverAnchor below) so PopoverContent can be sized to
// the row's own width — see TaskRowContent.vue's comment on why its own trigger, rendered
// inside that child component for task rows, still connects to this same Popover instance.
const isPreviewOpen = ref(false);
</script>

<template>
    <div class="flex flex-col">
        <Popover v-model:open="isPreviewOpen">
            <PopoverAnchor as-child>
                <div
                    class="group relative flex items-center gap-2.5 min-h-9 pr-2 rounded-md transition-colors"
                    :class="isSelected ? FLAT_ROW_SELECTED : (index !== undefined && index % 2 === 1 ? 'bg-projector-primary-100/70 dark:bg-projector-primary-950/25' : '')"
                >
                    <div v-if="isSelected" :class="FLAT_ROW_ACCENT_BAR"></div>

                    <button
                        v-if="item.children?.length"
                        type="button"
                        class="w-5 h-5 flex items-center justify-center shrink-0 rounded text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        @click="emit('toggleRoot', item.id)"
                    >
                        <ChevronRight class="w-3.5 h-3.5 transition-transform duration-200" :class="{ 'rotate-90': isTreeExpanded }" />
                    </button>
                    <span v-else class="w-5 h-5 shrink-0"></span>

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
                        @update="(field, val) => emit('updateTask', item.id, field, val)"
                    >
                        <template v-if="isReprocessable && !isReadOnly" #actions>
                            <button
                                type="button"
                                :disabled="item.currentStatus || item.processed_at === null"
                                class="h-7 px-2.5 flex items-center gap-1.5 rounded-md text-projector-highlight-600 dark:text-projector-highlight-400 hover:bg-projector-highlight-50 dark:hover:bg-projector-highlight-950/30 disabled:opacity-40 disabled:cursor-not-allowed transition-colors shrink-0"
                                @click="emit('handleReprocess', item.id)"
                            >
                                <Sparkles class="w-3.5 h-3.5" />
                                <span class="text-[9px] font-black uppercase tracking-widest">{{ processButtonLabel }}</span>
                            </button>
                        </template>
                    </TaskRowContent>

                    <template v-else>
                        <div class="w-4 h-4 flex items-center justify-center shrink-0" :class="isSelected ? 'text-projector-primary-600' : 'text-slate-400'">
                            <Folder v-if="isNotes" class="w-3.5 h-3.5" />
                            <FileText v-else class="w-3.5 h-3.5" />
                        </div>

                        <div class="flex-1 flex items-center gap-1.5 min-w-0">
                            <span
                                class="text-[13px]"
                                :class="[
                                    level === 0 ? 'font-bold' : 'font-medium',
                                    isProcessing ? 'text-slate-400 dark:text-slate-500' : 'text-slate-900 dark:text-slate-100',
                                    // Long titles are allowed to wrap onto multiple lines (growing the row)
                                    // when the window gets too narrow, rather than truncating with an
                                    // ellipsis — short titles keep the single-line truncate behavior always.
                                    item.name.length > 60 ? 'whitespace-normal break-words' : 'truncate'
                                ]"
                            >
                                {{ item.name }}
                            </span>

                            <span class="text-[9px] font-black uppercase tracking-widest text-slate-300 dark:text-slate-600 shrink-0">
                                {{ getDocLabel(item.type) }}
                            </span>

                            <span v-if="item.tasks?.length" class="flex items-center gap-1 text-[9px] font-black text-emerald-600 dark:text-emerald-400 shrink-0">
                                <CheckSquare class="w-2.5 h-2.5" /> {{ item.tasks.length }}
                            </span>

                            <span v-if="isProcessing" class="flex items-center gap-1.5 text-[10px] text-projector-primary-500 shrink-0">
                                <RefreshCw class="w-3 h-3 animate-spin" />
                                <span class="animate-pulse">{{ item.currentStatus || 'Processing...' }}</span>
                            </span>
                        </div>

                        <div class="hidden md:flex items-center shrink-0 ml-3">
                            <div
                                v-if="leadUser"
                                :class="[
                                    'h-6 w-6 rounded-full border flex items-center justify-center transition-all duration-300 bg-slate-100 dark:bg-slate-800 border-slate-200 dark:border-slate-700',
                                    { 'grayscale opacity-50': leadUser.isPending }
                                ]"
                                :title="leadUser.isPending ? `${leadUser.name} (hasn't logged in yet)` : undefined"
                            >
                                <span class="text-[9px] font-black uppercase text-slate-500">{{ leadUser.initials }}</span>
                            </div>
                            <div v-else class="h-6 w-6 rounded-full border border-dashed border-slate-200 dark:border-slate-800 flex items-center justify-center">
                                <span class="text-[8px] font-bold text-slate-300">--</span>
                            </div>
                        </div>
                    </template>

                    <!-- Task rows get their own copy of this same button inside TaskRowContent's
                         #actions slot above (so it lands between the title and the fields, not after
                         them) — this one is for non-task rows only. -->
                    <button
                        v-if="isReprocessable && !isReadOnly && !isTask"
                        type="button"
                        :disabled="item.currentStatus || item.processed_at === null"
                        class="h-7 px-2.5 flex items-center gap-1.5 rounded-md text-projector-highlight-600 dark:text-projector-highlight-400 hover:bg-projector-highlight-50 dark:hover:bg-projector-highlight-950/30 disabled:opacity-40 disabled:cursor-not-allowed transition-colors shrink-0 ml-2"
                        @click="emit('handleReprocess', item.id)"
                    >
                        <Sparkles class="w-3.5 h-3.5" />
                        <span class="text-[9px] font-black uppercase tracking-widest">{{ processButtonLabel }}</span>
                    </button>

                    <!-- Rendered (but made invisible) whenever this row could ever show Reprocess without
                         Transform (i.e. any non-task row — Notes or a locked/terminal document) so its
                         width is still reserved — otherwise Reprocess would sit one slot further right on
                         rows without a Transform button (e.g. Notes) than on rows with one (e.g. Action
                         Items), leaving the two out of alignment between parent/child rows in the tree. -->
                    <Popover v-if="!isReadOnly && !isTask" v-model:open="isTransformOpen">
                        <PopoverTrigger as-child>
                            <button
                                type="button"
                                :disabled="!!item.currentStatus || item.processed_at === null"
                                :tabindex="(!isLocked && !isNotes) ? undefined : -1"
                                :class="[
                                    'h-7 px-2.5 flex items-center gap-1.5 rounded-md text-projector-primary-600 dark:text-projector-primary-400 hover:bg-projector-primary-50 dark:hover:bg-projector-primary-950/30 disabled:opacity-40 disabled:cursor-not-allowed transition-colors shrink-0 ml-1',
                                    (!isLocked && !isNotes) ? '' : 'invisible pointer-events-none'
                                ]"
                            >
                                <GitBranch class="w-3.5 h-3.5" />
                                <span class="text-[9px] font-black uppercase tracking-widest">Transform</span>
                            </button>
                        </PopoverTrigger>
                        <PopoverContent align="center" class="p-0">
                            <TransformPicker :project-id="String(item.project_id)" :document-id="String(item.id)" @run="handleRunTransform" />
                        </PopoverContent>
                    </Popover>

                    <!-- Preview, non-task rows only — task rows render their own copy of this
                         trigger inside TaskRowContent.vue (connected to this same Popover via
                         provide/inject, not DOM nesting). Always visible (not hover-revealed)
                         and click-to-open; positioned last so it sits at the far right of the
                         row, after any Reprocess/Transform controls. -->
                    <PopoverTrigger v-if="!isTask" as-child>
                        <button
                            type="button"
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 data-[state=open]:bg-slate-100 data-[state=open]:text-slate-600 dark:text-slate-500 dark:hover:bg-slate-800 dark:hover:text-slate-300 dark:data-[state=open]:bg-slate-800 dark:data-[state=open]:text-slate-300"
                            title="Preview"
                        >
                            <Eye class="h-3.5 w-3.5" />
                        </button>
                    </PopoverTrigger>
                </div>
            </PopoverAnchor>
            <PopoverContent class="w-(--reka-popper-anchor-width) p-4" align="end">
                <DocumentPreviewCard :name="item.name" :content="item.content" :go-to-label="getDocLabel(item.type)" @open="goToDetails" />
            </PopoverContent>
        </Popover>

        <div v-if="isTreeExpanded && item.children?.length" class="relative pl-7">
            <div class="absolute left-[14px] top-0 bottom-0 w-px bg-slate-200 dark:bg-slate-800"></div>
            <TraceabilityRow
                v-for="(child, childIndex) in item.children"
                :key="'doc-' + child.id"
                :item="child"
                :index="childIndex"
                :level="level + 1"
                :reprocessable-types="reprocessableTypes"
                :ai-processed-parent-ids="aiProcessedParentIds"
                :expanded-root-ids="expandedRootIds"
                :get-doc-label="getDocLabel"
                :is-task-type="isTaskType"
                :selected-sheet-id="selectedSheetId"
                :get-lead-user="getLeadUser"
                :assignee-options="assigneeOptions"
                :uses-external-due-dates="usesExternalDueDates"
                :is-read-only="isReadOnly"
                :columns="columns"
                @toggle-root="id => emit('toggleRoot', id)"
                @handle-reprocess="id => emit('handleReprocess', id)"
                @handle-transition="(id, payload) => emit('handleTransition', id, payload)"
                @on-delete-requested="i => emit('onDeleteRequested', i)"
                @update-task="(id, field, val) => emit('updateTask', id, field, val)"
            />
        </div>
    </div>
</template>

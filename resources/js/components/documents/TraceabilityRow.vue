<script setup lang="ts">
import { computed } from 'vue';
import { ChevronRight, FileText, CheckSquare, Eye, Sparkles, RefreshCw } from 'lucide-vue-next';
import { useDocumentActions } from '@/composables/useDocumentActions';
import { statusDotClasses, priorityDotClasses, STATUS_LABELS, PRIORITY_LABELS } from '@/lib/constants';
import { getAvatarAppearance } from '@/lib/kanban-theme';
import { FLAT_ROW_HOVER, FLAT_ROW_SELECTED, FLAT_ROW_ACCENT_BAR } from '@/lib/flat-ui';

const props = defineProps<{
    item: any;
    level: number;
    reprocessableTypes: Set<string>;
    aiProcessedParentIds: Set<string>;
    activeEditingId: string | number | null;
    expandedRootIds: Set<string | number>;
    getDocLabel: (type: string) => string;
    isTaskType: (type: string) => boolean;
    selectedSheetId: string | number | null;
    getLeadUser: (doc: any) => any;
    users: any[];
    form: any;
    isReadOnly?: boolean;
}>();

const emit = defineEmits<{
    (e: 'toggleRoot', id: string | number): void;
    (e: 'handleReprocess', id: string ): void;
    (e: 'onDeleteRequested', item: any): void;
    (e: 'prepareEdit', item: any): void;
    (e: 'submit', callback: () => void): void;
}>();

const isTreeExpanded = computed(() => props.expandedRootIds instanceof Set && props.expandedRootIds.has(props.item.id));
const isSelected = computed(() => props.selectedSheetId === props.item.id);
const isTask = computed(() => props.isTaskType(props.item.type));
const isProcessing = computed(() => !!props.item.currentStatus || props.item.processed_at === null);

// Use the helper to get the lead user for this row
const leadUser = computed(() => props.getLeadUser(props.item));
const { navigateToDetails } = useDocumentActions({
    project: { id: props.item.project_id } as any
});

const isReprocessable = computed(() => props.reprocessableTypes.has(props.item.type));
const processButtonLabel = computed(() => props.aiProcessedParentIds.has(props.item.id) ? 'Reprocess' : 'Process');

const goToDetails = () => navigateToDetails(props.item.project_id, props.item.id);
</script>

<template>
    <div class="flex flex-col">
        <div
            class="group relative flex items-center gap-2.5 h-9 pr-2 rounded-md cursor-pointer transition-colors"
            :class="isSelected ? FLAT_ROW_SELECTED : FLAT_ROW_HOVER"
            @click="goToDetails"
        >
            <div v-if="isSelected" :class="FLAT_ROW_ACCENT_BAR"></div>

            <button
                v-if="item.children?.length"
                type="button"
                class="w-5 h-5 flex items-center justify-center shrink-0 rounded text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                @click.stop="emit('toggleRoot', item.id)"
            >
                <ChevronRight class="w-3.5 h-3.5 transition-transform duration-200" :class="{ 'rotate-90': isTreeExpanded }" />
            </button>
            <span v-else class="w-5 h-5 shrink-0"></span>

            <div class="w-4 h-4 flex items-center justify-center shrink-0" :class="isSelected ? 'text-projector-primary-600' : 'text-slate-400'">
                <CheckSquare v-if="isTask" class="w-3.5 h-3.5" />
                <FileText v-else class="w-3.5 h-3.5" />
            </div>

            <div class="flex-1 flex items-center gap-2.5 min-w-0">
                <span
                    class="text-[13px] truncate"
                    :class="[
                        level === 0 ? 'font-bold' : 'font-medium',
                        isProcessing ? 'text-slate-400 dark:text-slate-500' : 'text-slate-900 dark:text-slate-100'
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

            <button
                v-if="isReprocessable && !isReadOnly"
                type="button"
                :disabled="item.currentStatus || item.processed_at === null"
                class="h-7 px-2.5 flex items-center gap-1.5 rounded-md text-projector-highlight-600 dark:text-projector-highlight-400 hover:bg-projector-highlight-50 dark:hover:bg-projector-highlight-950/30 disabled:opacity-40 disabled:cursor-not-allowed transition-colors shrink-0 ml-2"
                @click.stop="emit('handleReprocess', item.id)"
            >
                <Sparkles class="w-3.5 h-3.5" />
                <span class="text-[9px] font-black uppercase tracking-widest">{{ processButtonLabel }}</span>
            </button>

            <div class="hidden md:flex items-center gap-3 shrink-0 ml-3">
                <div
                    v-if="leadUser"
                    :class="[
                        'h-6 w-6 rounded-full border flex items-center justify-center',
                        isTask ? getAvatarAppearance(leadUser.id) : 'bg-slate-100 dark:bg-slate-800 border-slate-200 dark:border-slate-700'
                    ]"
                >
                    <span :class="['text-[9px] font-black uppercase', isTask ? '' : 'text-slate-500']">{{ leadUser.initials }}</span>
                </div>
                <div v-else class="h-6 w-6 rounded-full border border-dashed border-slate-200 dark:border-slate-800 flex items-center justify-center">
                    <span class="text-[8px] font-bold text-slate-300">--</span>
                </div>

                <template v-if="isTask">
                    <div v-if="item.priority" class="flex items-center gap-1.5">
                        <span :class="['w-1.5 h-1.5 rounded-full shrink-0', priorityDotClasses[item.priority] ?? priorityDotClasses.low]"></span>
                        <span class="text-[9px] font-bold uppercase tracking-wider text-slate-400">{{ PRIORITY_LABELS[item.priority] ?? item.priority }}</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span :class="['w-1.5 h-1.5 rounded-full shrink-0', statusDotClasses[item.task_status ?? 'todo']]"></span>
                        <span class="text-[9px] font-bold uppercase tracking-wider text-slate-400">{{ STATUS_LABELS[item.task_status ?? 'todo'] }}</span>
                    </div>
                </template>
            </div>

            <div class="flex items-center gap-1 shrink-0 ml-2">
                <button
                    type="button"
                    class="h-7 w-7 flex items-center justify-center rounded-md text-slate-400 hover:text-projector-primary-600 hover:bg-projector-primary-50 dark:hover:bg-projector-primary-950/30 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-colors"
                    title="Open details"
                    @click.stop="goToDetails"
                >
                    <Eye class="w-3.5 h-3.5" />
                </button>
            </div>
        </div>

        <div v-if="isTreeExpanded && item.children?.length" class="relative pl-7">
            <div class="absolute left-[14px] top-0 bottom-0 w-px bg-slate-200 dark:bg-slate-800"></div>
            <TraceabilityRow
                v-for="child in item.children"
                :key="'doc-' + child.id"
                :item="child"
                :level="level + 1"
                :reprocessable-types="reprocessableTypes"
                :ai-processed-parent-ids="aiProcessedParentIds"
                :active-editing-id="activeEditingId"
                :expanded-root-ids="expandedRootIds"
                :get-doc-label="getDocLabel"
                :is-task-type="isTaskType"
                :selected-sheet-id="selectedSheetId"
                :get-lead-user="getLeadUser"
                :users="users"
                :form="form"
                :is-read-only="isReadOnly"
                @toggle-root="id => emit('toggleRoot', id)"
                @handle-reprocess="id => emit('handleReprocess', id)"
                @on-delete-requested="i => emit('onDeleteRequested', i)"
                @prepare-edit="i => emit('prepareEdit', i)"
                @submit="cb => emit('submit', cb)"
            />
        </div>
    </div>
</template>

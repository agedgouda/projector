<script setup lang="ts">
import { Search, ListFilter } from 'lucide-vue-next';
import { Input } from '@/components/ui/input';
import KanbanHeader from './KanbanHeader.vue';
import KanbanRow from './KanbanRow.vue';
import { FLAT_SEARCH_ICON, FLAT_SEARCH_INPUT } from '@/lib/flat-ui';
import { ALL_PRIORITIES, type Priority } from '@/composables/kanban/useKanbanQueries';
import { getPriorityStyles } from '@/lib/kanban-theme';

defineProps<{
    currentProject?: Project | null;
    hasVisibleTasks: boolean;
    columnStatuses: TaskStatus[];
    workflowRows: DocumentSchemaItem[];
    getColumnTaskCount: (status: TaskStatus) => number;
    getTasksByRowAndStatus: (rowKey: string, status: TaskStatus) => ProjectDocument[];
    onDragChange: (event: any, status: TaskStatus, rowKey: string) => void;
    openDetail: (doc: ProjectDocument) => void;
    handleCreateNew: (rowKey: string) => void;
    canViewProjectDetails?: boolean;
}>();

const searchQuery = defineModel<string>('searchQuery', { default: '' });
const selectedPriorities = defineModel<Priority[]>('selectedPriorities', { default: () => [...ALL_PRIORITIES] });

const togglePriority = (priority: Priority) => {
    const current = selectedPriorities.value;
    const idx = current.indexOf(priority);
    if (idx === -1) {
        selectedPriorities.value = [...current, priority];
    } else {
        selectedPriorities.value = current.filter(p => p !== priority);
    }
};
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative w-full md:w-80 lg:w-96 group">
                <Search :class="FLAT_SEARCH_ICON" />
                <Input
                    v-model="searchQuery"
                    placeholder="Search tasks or people... (Esc)"
                    :class="FLAT_SEARCH_INPUT"
                />
            </div>

            <div class="flex items-center gap-2 ml-auto">
                <ListFilter class="w-3.5 h-3.5 text-gray-400" />
                <span class="text-[10px] font-black uppercase tracking-widest text-gray-400">Priority:</span>
                <button
                    v-for="priority in ALL_PRIORITIES"
                    :key="priority"
                    type="button"
                    @click="togglePriority(priority)"
                    :disabled="selectedPriorities.includes(priority) && selectedPriorities.length === 1"
                    :class="[
                        'flex items-center gap-1.5 px-2.5 py-1 rounded border text-[9px] font-black uppercase tracking-tighter transition-all',
                        selectedPriorities.includes(priority)
                            ? getPriorityStyles(priority)
                            : 'bg-white text-gray-300 border-gray-200 line-through'
                    ]"
                >
                    {{ priority }}
                </button>
            </div>
        </div>

        <div v-if="hasVisibleTasks" class="block w-full min-w-0">
            <KanbanHeader
                :column-statuses="columnStatuses"
                :get-count="getColumnTaskCount"
            />

            <div class="space-y-1 w-full block">
                <KanbanRow
                    v-for="row in workflowRows"
                    :key="row.key"
                    :row="row"
                    :column-statuses="columnStatuses"
                    :get-tasks="(rowKey, status) => getTasksByRowAndStatus(rowKey, status)"
                    :on-drag="(evt, status) => onDragChange(evt, status, row.key)"
                    :on-open="openDetail"
                    :on-create="(key) => handleCreateNew(key)"
                    :can-view-project-details="canViewProjectDetails"
                    :grid-style="{
                        gridTemplateColumns: `repeat(${columnStatuses.length}, minmax(0, 1fr))`
                    }"
                />
            </div>
        </div>

        <div v-else class="flex flex-col items-center justify-center py-20 bg-gray-50/50 rounded-[2rem] border border-dashed border-gray-200">
            <div class="p-4 bg-white rounded-2xl shadow-sm mb-4">
                <Search class="w-8 h-8 text-gray-300" />
            </div>
            <p class="text-gray-900 font-bold">No tasks found</p>
            <p class="text-gray-500 text-sm">Try adjusting your search or filters.</p>
        </div>
    </div>
</template>

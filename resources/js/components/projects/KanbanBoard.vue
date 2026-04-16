<script setup lang="ts">
import { Search, X } from 'lucide-vue-next';
import { Input } from '@/components/ui/input';
import KanbanHeader from './KanbanHeader.vue';
import KanbanRow from './KanbanRow.vue';

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
}>();

const searchQuery = defineModel<string>('searchQuery', { default: '' });
</script>

<template>
    <div v-if="hasVisibleTasks" class="space-y-6">
        <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-white dark:bg-slate-900 p-2 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
            <div class="relative w-full md:w-80 lg:w-96">
                    <Search class="absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
                    <Input
                        v-model="searchQuery"
                        placeholder="Search tasks or people... (Esc)"
                        class="pl-11 pr-10 bg-slate-50 dark:bg-slate-950 border-none h-11 rounded-xl focus-visible:ring-1 focus-visible:ring-slate-300"
                    />
                    <button
                        v-if="searchQuery"
                        @click="searchQuery = ''"
                        class="absolute right-3 top-1/2 -translate-y-1/2 p-0.5 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-full transition-colors"
                    >
                        <X class="w-3 h-3 text-slate-500" />
                    </button>
            </div>
        </div>

        <div class="block w-full min-w-0">
            <KanbanHeader
                :column-statuses="columnStatuses"
                :get-count="getColumnTaskCount"
            />

            <div v-if="searchQuery && !hasVisibleTasks" class="flex flex-col items-center justify-center py-20 bg-gray-50/50 rounded-[2rem] border border-dashed border-gray-200">
                <div class="p-4 bg-white rounded-2xl shadow-sm mb-4">
                    <Search class="w-8 h-8 text-gray-300" />
                </div>
                <p class="text-gray-900 font-bold">No tasks found</p>
                <p class="text-gray-500 text-sm">Try adjusting your search query or press Escape.</p>
            </div>

            <div v-else class="space-y-1 w-full block">
                <KanbanRow
                    v-for="row in workflowRows"
                    :key="row.key"
                    :row="row"
                    :column-statuses="columnStatuses"
                    :get-tasks="(rowKey, status) => getTasksByRowAndStatus(rowKey, status)"
                    :on-drag="(evt, status) => onDragChange(evt, status, row.key)"
                    :on-open="openDetail"
                    :on-create="(key) => handleCreateNew(key)"
                    :grid-style="{
                        gridTemplateColumns: `repeat(${columnStatuses.length}, minmax(0, 1fr))`
                    }"
                />
            </div>
        </div>
    </div>
</template>

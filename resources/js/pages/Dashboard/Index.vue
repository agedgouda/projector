<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { onKeyStroke } from '@vueuse/core';
import { Search, X } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import { STATUS_LABELS } from '@/lib/constants';
import { useKanbanBoard } from '@/composables/kanban/useKanbanBoard';

import ProjectSwitcher from './Partials/ProjectSwitcher.vue';
import KanbanHeader from './Partials/KanbanHeader.vue';
import KanbanRow from './Partials/KanbanRow.vue';
import DocumentDetailSheet from './Partials/DocumentDetailSheet.vue';
import { KANBAN_UI } from '@/lib/kanban-theme';

const props = defineProps<{
    projects: Project[];
    currentProject: Project | null;
    kanbanData: Record<string, ProjectDocument[]>;
}>();

const columnStatuses = Object.keys(STATUS_LABELS) as TaskStatus[];

const {
    selectedDocument,
    isSheetOpen,
    handleCreateNew,
    getTasksByRowAndStatus,
    getColumnTaskCount,
    updateAttribute,
    onDragChange,
    openDetail,
    canCreateTask,
    searchQuery
} = useKanbanBoard(props);

onKeyStroke('Escape', () => {
    searchQuery.value = '';
});

const workflowRows = computed(() => props.currentProject?.type?.document_schema?.filter(s => s.is_task) || []);

const breadcrumbs = computed(() => [
    { title: 'Dashboard', href: '/dashboard' },
    { title: props.currentProject?.name ?? 'Select Project', href: '' }
]);

// Check if the board is empty based on the full column list
const hasVisibleTasks = computed(() => {
    return columnStatuses.some(status => getColumnTaskCount(status) > 0);
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-8 space-y-8 w-full">

            <div class="w-full">
                <ProjectSwitcher
                    :projects="projects"
                    :current-project="currentProject"
                    @switch="(id) => router.get('/dashboard', { project: id })"
                />
            </div>
            <div class="flex flex-col md:flex-row md:items-center justify-start gap-4">
                <div v-if="currentProject && hasVisibleTasks" class="relative w-full md:w-80 group">
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 group-focus-within:text-indigo-500 transition-colors" />
                    <input
                        v-model="searchQuery"
                        placeholder="Search tasks or people... (Esc)"
                        class="w-full pl-10 pr-10 py-2.5 bg-gray-50 border border-gray-200 rounded-2xl text-sm focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 outline-none transition-all"
                    />
                    <button
                        v-if="searchQuery"
                        @click="searchQuery = ''"
                        class="absolute right-3 top-1/2 -translate-y-1/2 p-0.5 hover:bg-gray-200 rounded-full transition-colors"
                    >
                        <X class="w-3 h-3 text-gray-500" />
                    </button>
                </div>
            </div>

            <div v-if="currentProject && hasVisibleTasks" class="block w-full min-w-0">
                <KanbanHeader
                    :column-statuses="columnStatuses"
                    :get-count="getColumnTaskCount"
                    :class="KANBAN_UI.columnHeader"
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
                        :can-create-task="canCreateTask"
                        :get-tasks="getTasksByRowAndStatus"
                        :on-drag="onDragChange"
                        :on-open="openDetail"
                        :on-create="(key) => handleCreateNew(key)"
                        :grid-style="{
                            gridTemplateColumns: `repeat(${columnStatuses.length}, minmax(0, 1fr))`
                        }"
                    />
                </div>
            </div>
        </div>

        <DocumentDetailSheet
            v-if="selectedDocument"
            v-model:open="isSheetOpen"
            :document="selectedDocument as ProjectDocument"
            @update-attribute="(attr, val) => updateAttribute(
                selectedDocument!.id,
                { [attr]: val },
                'Changes saved'
            )"
        />
    </AppLayout>
</template>

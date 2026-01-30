<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { onKeyStroke } from '@vueuse/core';
import { Search, X } from 'lucide-vue-next';
import { toast } from 'vue-sonner';
import AppLayout from '@/layouts/AppLayout.vue';
import { STATUS_LABELS } from '@/lib/constants';
import { useKanbanBoard } from '@/composables/kanban/useKanbanBoard';
import { useAiProcessing } from '@/composables/useAiProcessing';
import { useDocumentActions } from '@/composables/useDocumentActions';
import { useWorkflow } from '@/composables/useWorkflow';
import { KANBAN_UI } from '@/lib/kanban-theme';

// UI Components
import ProjectSwitcher from './Partials/ProjectSwitcher.vue';
import KanbanHeader from './Partials/KanbanHeader.vue';
import KanbanRow from './Partials/KanbanRow.vue';
import DocumentDetailSheet from './Partials/DocumentDetailSheet.vue';
import AiProgressBar from '@/components/AiProgressBar.vue';
import AiProcessingHeader from '@/components/AiProcessingHeader.vue';

const props = defineProps<{
    projects: Project[];
    currentProject: Project | null;
    kanbanData: Record<string, ProjectDocument[]>;
}>();

const columnStatuses = Object.keys(STATUS_LABELS) as TaskStatus[];

// --- 1. KANBAN BASE LOGIC ---
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
    searchQuery,
    applyLocalUpdate,
    localKanbanData
} = useKanbanBoard(props);




// --- 2. AI PROCESSING (OBSERVER MODE) ---
// We use a null ref because this page doesn't start the creation, it just watches it.
const aiStatusMessageRef = ref('');
const workflowRows = computed(() => props.currentProject?.type?.document_schema?.filter(s => s.is_task) || []);

const {
    setDocToProcessing,

} = useDocumentActions(
    {
        project: props.currentProject as Project,
        documentSchema: workflowRows.value // or props.currentProject?.type?.document_schema
    },
    aiStatusMessageRef,
    applyLocalUpdate // This ensures the UI reflects changes immediately
);

const targetBeingCreated = ref<string | null>(null);

const allDocs = computed(() => {
    return Object.values(localKanbanData.value).flat() as ProjectDocument[];
});
const projectIdForEcho = computed(() => props.currentProject?.id?.toString() ?? null);

const { aiStatusMessage, aiProgress, isAiProcessing } = useAiProcessing(
    projectIdForEcho.value ?? 'NO_PROJECT',
    allDocs,
    targetBeingCreated,
    // CALLBACK: When Echo broadcasts a change
    (incomingDoc: any) => {
        // This is the magic: as the AI works on the 'other page',
        // the cards here will update or appear in real-time.
        applyLocalUpdate(incomingDoc.id, incomingDoc);
    },
    // CALLBACK: Global Success
    () => {
        toast.success('Project Synced', { description: 'AI processing task completed.' });
    },
    // CALLBACK: Global Error
    (errorMessage) => {
        toast.error('AI Sync Error', { description: errorMessage });
    }
);

// --- 3. UI METHODS & BREADCRUMBS ---
onKeyStroke('Escape', () => {
    searchQuery.value = '';
});

watch(aiStatusMessage, (val) => aiStatusMessageRef.value = val);

const breadcrumbs = computed(() => [
    { title: 'Dashboard', href: '/dashboard' },
    { title: props.currentProject?.name ?? 'Select Project', href: '' }
]);

const hasVisibleTasks = computed(() => {
    return columnStatuses.some(status => getColumnTaskCount(status) > 0);
});

const { reprocessableTypes } = useWorkflow(props.currentProject);

const handleReprocess = (id: string | number) => {
    const stringId = id.toString();
    const doc = allDocs.value.find(d => d.id.toString() === stringId) as UIProjectDocument | undefined;

    if (!doc) return;

    // Start the UI progress bar immediately
    aiProgress.value = 5;

    // Set local state to loading/processing
    void setDocToProcessing(doc);

    // Optional: Close the detail sheet so the user can see the progress on the board
    isSheetOpen.value = false;
};


</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div v-if="!currentProject" class="p-8 flex flex-col items-center justify-center min-h-[60vh]">
            <div class="p-4 bg-gray-100 rounded-full mb-4">
                <ShieldAlert class="w-12 h-12 text-gray-400" />
            </div>
            <h2 class="text-xl font-bold text-gray-900">No Projects Found</h2>
            <p class="text-gray-500 max-w-xs text-center">
                You haven't been assigned to any projects yet. Please contact your administrator.
            </p>
        </div>

        <div v-else class="p-8 space-y-8 w-full">
            <AiProgressBar :is-processing="isAiProcessing" :progress="aiProgress" />

            <AiProcessingHeader
                :is-processing="isAiProcessing"
                :progress="aiProgress"
                :message="aiStatusMessage"
            />

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
            :reprocessable-types="reprocessableTypes"
            v-model:open="isSheetOpen"
            :document="selectedDocument as ProjectDocument"
             @handle-reprocess="handleReprocess"
            @update-attribute="(attr, val) => updateAttribute(
                selectedDocument!.id,
                { [attr]: val },
                'Changes saved'
            )"
        />
    </AppLayout>
</template>

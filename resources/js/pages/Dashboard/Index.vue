<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { onKeyStroke } from '@vueuse/core';
import { toast } from 'vue-sonner';
import { ShieldAlert } from 'lucide-vue-next';
import axios from 'axios';

import AppLayout from '@/layouts/AppLayout.vue';

import { STATUS_LABELS } from '@/lib/constants';
import { setPersistentCookie } from '@/lib/utils';
import { useKanbanBoard } from '@/composables/kanban/useKanbanBoard';
import { useAiProcessing } from '@/composables/useAiProcessing';
import { useWorkflow } from '@/composables/useWorkflow';

// UI Components
import KanbanBoard from '@/components/projects/KanbanBoard.vue';
import DocumentDetailSheet from '@/components/projects/DocumentDetailSheet.vue';
import AiProgressBar from '@/components/AiProgressBar.vue';
import AiProcessingHeader from '@/components/AiProcessingHeader.vue';

const props = defineProps<{
    projects: Project[];
    kanbanData: Record<string, ProjectDocument[]>;
    activeTab: string;
    clients: Client[];
    projectTypes: ProjectType[];
    currentOrganization: { id: string; name: string } | null;
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
    searchQuery,
    applyLocalUpdate,
    localKanbanData
} = useKanbanBoard(props);

const activeTab = ref(props.activeTab);
const workflowRows = computed(() =>
    Object.keys(props.kanbanData).map(projectId => {
        const project = props.projects.find(p => p.id === projectId);
        return { key: projectId, label: project?.name ?? projectId, is_task: true };
    })
);

const targetBeingCreated = ref<string | null>(null);

const allDocs = computed(() => {
    return Object.values(localKanbanData.value).flat() as ProjectDocument[];
});

// --- 2. AI PROCESSING — one listener per project, aggregated state ---
const aiInstances = props.projects.map(project =>
    useAiProcessing(
        project.id,
        allDocs,
        targetBeingCreated,
        (incomingDoc: any) => { applyLocalUpdate(incomingDoc.id, incomingDoc); },
        () => { toast.success('Project Synced', { description: 'AI processing task completed.' }); },
        (errorMessage) => { toast.error('AI Sync Error', { description: errorMessage }); }
    )
);

const isAiProcessing = computed(() => aiInstances.some(i => i.isAiProcessing.value));
const aiProgress = computed(() => Math.max(0, ...aiInstances.map(i => i.aiProgress.value)));
const aiStatusMessage = computed(() => aiInstances.find(i => i.aiStatusMessage.value)?.aiStatusMessage.value ?? '');

// --- 3. UI METHODS & BREADCRUMBS ---
onKeyStroke('Escape', () => {
    searchQuery.value = '';
});

const breadcrumbs = computed(() => [
    { title: props.currentOrganization ? `Dashboard ${props.currentOrganization.name}` : 'Dashboard', href: '/dashboard' },
]);

const hasVisibleTasks = computed(() => {
    return columnStatuses.some(status => getColumnTaskCount(status) > 0);
});

// Reprocess: look up the doc's project inline — no currentProject needed
const handleReprocess = async (id: string | number) => {
    const doc = allDocs.value.find(d => d.id.toString() === id.toString());
    if (!doc) return;

    if (!confirm('Are you sure you want to generate the next workflow step?')) return;

    isSheetOpen.value = false;

    try {
        await axios.post(`/projects/${doc.project_id}/documents/${doc.id}/reprocess`);
    } catch {
        toast.error('Failed to start reprocessing.');
    }
};

// Reprocessable types based on the selected document's project
const selectedDocumentProject = computed(() =>
    props.projects.find(p => p.id === (selectedDocument.value as any)?.project_id) ?? null
);
const { reprocessableTypes } = useWorkflow(selectedDocumentProject);

const updateTab = (tab: string) => {
    activeTab.value = tab;
    setPersistentCookie('last_active_tab', tab);

    router.get(window.location.pathname,
        { tab },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true
        }
    );
};

</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div v-if="!projects.length" class="p-8 flex flex-col items-center justify-center min-h-[60vh]">
            <div class="p-4 bg-gray-100 rounded-full mb-4">
                <ShieldAlert class="w-12 h-12 text-gray-400" />
            </div>
            <h2 class="text-xl font-bold text-gray-900">No Projects Found</h2>
            <p class="text-gray-500 max-w-xs text-center">
                There are no projects in this organization yet.
            </p>
        </div>

        <div v-else class="p-8 space-y-8 w-full">
            <AiProgressBar :is-processing="isAiProcessing" :progress="aiProgress" />

            <AiProcessingHeader
                :is-processing="isAiProcessing"
                :progress="aiProgress"
                :message="aiStatusMessage"
            />

            <div class="w-full flex items-center gap-3">
                <span v-if="currentOrganization" class="text-lg font-bold text-gray-900 dark:text-gray-100">
                    {{ currentOrganization.name }}
                </span>
            </div>

            <div class="flex items-center border-b border-gray-200 dark:border-gray-700 mb-6">
                <button v-for="tab in ['tasks']" :key="tab"
                    @click="updateTab(tab)"
                    :class="['px-8 py-4 text-[10px] font-black uppercase tracking-[0.2em] transition-all border-b-2 -mb-[1px]',
                        activeTab === tab ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-400 hover:text-gray-600']">
                    Tasks
                </button>
            </div>

            <div v-show="activeTab === 'tasks'">
                <KanbanBoard
                    v-model:searchQuery="searchQuery"
                    :has-visible-tasks="hasVisibleTasks"
                    :column-statuses="columnStatuses"
                    :workflow-rows="workflowRows"
                    :get-column-task-count="getColumnTaskCount"
                    :get-tasks-by-row-and-status="getTasksByRowAndStatus"
                    :on-drag-change="onDragChange"
                    :open-detail="openDetail"
                    :handle-create-new="handleCreateNew"
                />
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

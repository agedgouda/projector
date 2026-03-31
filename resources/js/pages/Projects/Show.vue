<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, Deferred } from '@inertiajs/vue3';
import { onKeyStroke } from '@vueuse/core';
import { toast } from 'vue-sonner';
import { PlusIcon, ShieldAlert } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import AvailableRecordings from '@/pages/Projects/Partials/AvailableRecordings.vue';

import AppLayout from '@/layouts/AppLayout.vue';

import { STATUS_LABELS } from '@/lib/constants';
import { setPersistentCookie } from '@/lib/utils';
import { useEchoWatchdog } from '@/composables/useEchoWatchdog';
import { useKanbanBoard } from '@/composables/kanban/useKanbanBoard';
import { useAiProcessing } from '@/composables/useAiProcessing';
import { useDocumentActions } from '@/composables/useDocumentActions';
import { useWorkflow } from '@/composables/useWorkflow';
import DocumentManager from '@/components/documents/DocumentManager.vue';
import projectRoutes from '@/routes/projects/index';
import projectDocumentsRoutes from '@/routes/projects/documents/index';


// UI Components
import ProjectSwitcher from '@/components/projects/ProjectSwitcher.vue';
import LifecycleStepPicker from '@/components/LifecycleStepPicker.vue';
import KanbanBoard from '@/components/projects/KanbanBoard.vue';
import DocumentDetailSheet from '@/components/projects/DocumentDetailSheet.vue';
import AiProgressBar from '@/components/AiProgressBar.vue';
import AiProcessingHeader from '@/components/AiProcessingHeader.vue';

const props = defineProps<{
    projects: Project[];
    currentProject: Project | null;
    kanbanData: Record<string, ProjectDocument[]>;
    activeTab: string;
    clients: Client[];
    projectTypes: ProjectType[];
    canManageTranscripts: boolean;
    meetingProvider: string | null;
    recordingsData?: {
        recordings: Recording[];
        importedIds: string[];
        crossProjectImportedIds: string[];
        providerError: string | null;
        canManage: boolean;
    };
}>();

const columnStatuses = Object.keys(STATUS_LABELS) as TaskStatus[];

useEchoWatchdog(() => props.currentProject?.id);

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

// --- 2. AI PROCESSING (OBSERVER MODE) ---
const aiStatusMessageRef = ref('');
const activeTab = ref(props.activeTab);
const workflowRows = computed(() =>
    Object.keys(props.kanbanData).map(projectId => {
        const project = props.projects.find(p => p.id === projectId);
        return { key: projectId, label: project?.name ?? projectId, is_task: true };
    })
);
const currentProjectDocumentSchema = computed(() =>
    props.currentProject?.type?.document_schema?.filter((s: any) => s.is_task) ?? []
);

const {
    setDocToProcessing,
} = useDocumentActions(
    {
        project: props.currentProject as Project,
        documentSchema: currentProjectDocumentSchema.value
    },
    aiStatusMessageRef,
    applyLocalUpdate
);

const targetBeingCreated = ref<string | null>(null);
const isGenerating = ref(false);

const allDocs = computed(() => {
    return Object.values(localKanbanData.value).flat() as ProjectDocument[];
});

const projectIdForEcho = computed(() => props.currentProject?.id?.toString() ?? null);

const { aiStatusMessage, aiProgress, isAiProcessing } = useAiProcessing(
    projectIdForEcho.value ?? 'NO_PROJECT',
    allDocs,
    targetBeingCreated,
    (incomingDoc: any) => {
        applyLocalUpdate(incomingDoc.id, incomingDoc);
    },
    () => {
        toast.success('Project Synced', { description: 'AI processing task completed.' });
    },
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
    { title: 'Projects', href: '/projects' },
    { title: props.currentProject?.name ?? 'Select Project', href: '' }
]);

const hasVisibleTasks = computed(() => {
    return columnStatuses.some(status => getColumnTaskCount(status) > 0);
});

const { reprocessableTypes } = useWorkflow(props.currentProject);

const onImportQueued = () => {
    targetBeingCreated.value = 'transcript';
    aiProgress.value = 5;
    activeTab.value = 'hierarchy';
    setPersistentCookie('last_active_tab', 'hierarchy');
};

const handleReprocess = (id: string | number) => {
    const stringId = id.toString();
    const doc = allDocs.value.find(d => d.id.toString() === stringId) as UIProjectDocument | undefined;

    if (!doc) return;

    aiProgress.value = 5;
    void setDocToProcessing(doc);
    isSheetOpen.value = false;
};

const updateTab = (tab: string) => {
    activeTab.value = tab;
    setPersistentCookie('last_active_tab', tab);

    router.get(window.location.pathname,
        {
            project: props.currentProject?.id,
            tab: tab
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            except: ['recordingsData'],
        }
    );
};

const generateDeliverables = () => {
    if (!props.currentProject) return;
    router.post(projectRoutes.generate.url(props.currentProject.id), {}, {
        onBefore: () => { isGenerating.value = true; },
        onFinish: () => { isGenerating.value = false; }
    });
};

// --- 4. DOCUMENT MANAGER ACTIONS ---
const confirmDelete = (doc: ProjectDocument) => {
    if (!props.currentProject) return;

    if (confirm(`Are you sure you want to delete ${doc.name}?`)) {
        router.delete(projectDocumentsRoutes.destroy({
            project: props.currentProject.id,
            document: doc.id
        }).url, {
            onSuccess: () => toast.success('Document deleted'),
            onError: () => toast.error('Failed to delete document')
        });
    }
};

const handleCreateNavigation = (projectId: string) => {
    router.visit(projectDocumentsRoutes.create({ project: projectId }).url, {
        data: {
            redirect: window.location.href
        }
    });
};

watch(() => props.currentProject, (newProject) => {
    if (newProject?.id) {
        localStorage.setItem('last_project_id', newProject.id.toString());
    }
}, { immediate: true });

</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div v-if="!currentProject" class="p-6 flex flex-col items-center justify-center min-h-[60vh]">
            <div class="p-4 bg-gray-100 rounded-full mb-4">
                <ShieldAlert class="w-12 h-12 text-gray-400" />
            </div>
            <h2 class="text-xl font-bold text-gray-900">No Projects Found</h2>
            <p class="text-gray-500 max-w-xs text-center">
                You haven't been assigned to any projects yet. Please contact your administrator.
            </p>
        </div>

        <div v-else class="p-6 space-y-8 w-full">
            <AiProgressBar :is-processing="isAiProcessing" :progress="aiProgress" />

            <AiProcessingHeader
                :is-processing="isAiProcessing"
                :progress="aiProgress"
                :message="aiStatusMessage"
            />

            <div class="w-full flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3 w-full sm:w-auto">
                    <ProjectSwitcher
                        :projects="projects"
                        :current-project="currentProject"
                        :clients="clients"
                        :project-types="projectTypes"
                        @switch="(id) => router.get('/projects/' + id)"
                    />
                    <LifecycleStepPicker :project="currentProject" />
                </div>

                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <Button
                        @click="handleCreateNavigation(currentProject.id)"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl px-6 h-11 font-bold whitespace-nowrap"
                    >
                        <PlusIcon class="h-4 w-4 mr-2" /> New Intake
                    </Button>
                </div>
            </div>

            <div class="flex items-center border-b border-gray-200 dark:border-gray-700 mb-6">
                <button v-for="tab in ['tasks', 'hierarchy', 'recordings']" :key="tab"
                    @click="updateTab(tab)"
                    :class="['px-8 py-4 text-[10px] font-black uppercase tracking-[0.2em] transition-all border-b-2 -mb-[1px]',
                        activeTab === tab ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-400 hover:text-gray-600']">
                    {{ tab === 'hierarchy' ? 'Documentation' : tab === 'recordings' ? 'Recordings' : 'Tasks' }}
                </button>
            </div>

            <div v-show="activeTab === 'tasks'">

                <KanbanBoard
                    v-model:searchQuery="searchQuery"
                    :current-project="currentProject"
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

            <div v-show="activeTab === 'hierarchy'">

                <DocumentManager
                    :project="currentProject"
                    :live-documents="currentProject.documents"
                    :is-generating="isGenerating"
                    @confirm-delete="confirmDelete"
                    @generate="generateDeliverables"
                />
            </div>

            <div v-show="activeTab === 'recordings'">
                <div v-if="!meetingProvider" class="flex flex-col items-center justify-center py-16 rounded-2xl border border-dashed border-gray-200 dark:border-gray-700">
                    <p class="text-sm font-bold text-gray-500">No meeting provider configured</p>
                    <p class="text-xs text-gray-400 mt-1">Configure a provider in Organization Settings to import recordings.</p>
                </div>

                <Deferred v-else data="recordingsData">
                    <template #fallback>
                        <div class="space-y-3">
                            <div v-for="i in 4" :key="i" class="flex items-center gap-4 p-4 rounded-2xl border border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 animate-pulse">
                                <div class="w-8 h-8 rounded-xl bg-gray-100 dark:bg-gray-800 shrink-0" />
                                <div class="flex-1 space-y-2">
                                    <div class="h-3 bg-gray-100 dark:bg-gray-800 rounded w-1/3" />
                                    <div class="h-2.5 bg-gray-100 dark:bg-gray-800 rounded w-1/5" />
                                </div>
                                <div class="h-9 w-24 bg-gray-100 dark:bg-gray-800 rounded-xl" />
                            </div>
                        </div>
                    </template>

                    <AvailableRecordings
                        :project-id="currentProject.id"
                        :recordings="recordingsData!.recordings"
                        :imported-ids="recordingsData!.importedIds"
                        :cross-project-imported-ids="recordingsData!.crossProjectImportedIds"
                        :can-manage="recordingsData!.canManage"
                        :provider-error="recordingsData!.providerError"
                        @import-queued="onImportQueued"
                        @import-failed="targetBeingCreated = null"
                    />
                </Deferred>
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

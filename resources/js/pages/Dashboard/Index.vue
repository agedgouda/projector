<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { onKeyStroke } from '@vueuse/core';
import axios from 'axios';
import { Coffee } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import AppLayout from '@/layouts/AppLayout.vue';

import { useKanbanBoard } from '@/composables/kanban/useKanbanBoard';
import { useAiProcessing } from '@/composables/useAiProcessing';
import { useWorkflow } from '@/composables/useWorkflow';
import {
    redirectIfLoggedOut,
    redirectIfSessionExpiredError,
} from '@/lib/sessionExpiry';

// UI Components
import AiProcessingHeader from '@/components/AiProcessingHeader.vue';
import AiProgressBar from '@/components/AiProgressBar.vue';
import DocumentDetailSheet from '@/components/projects/DocumentDetailSheet.vue';
import KanbanBoard from '@/components/projects/KanbanBoard.vue';

const props = defineProps<{
    projects: Project[];
    kanbanData: Record<string, ProjectDocument[]>;
    clients: Client[];
    currentOrganization: { id: string; name: string } | null;
    canViewProjectDetails: boolean;
}>();

const page = usePage<{ flash?: { success?: string; error?: string } }>();

onMounted(() => {
    const flash = page.props.flash;
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
});

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    },
    { deep: true },
);

// --- 1. KANBAN BASE LOGIC ---
const {
    selectedDocument,
    isSheetOpen,
    handleCreateNew,
    getTasksByRowAndStatus,
    getTaskCountByRowAndStatus,
    matchesFilters,
    updateAttribute,
    updateTags,
    onDragChange,
    openDetail,
    searchQuery,
    selectedPriorities,
    sortBy,
    availableTags,
    selectedTagIds,
    projectsById,
    assigneeOptionsByProjectId,
    applyLocalUpdate,
    removeLocalDocuments,
    localKanbanData,
} = useKanbanBoard(props);

const workflowRows = computed(() =>
    Object.keys(props.kanbanData).map((projectId) => {
        const project = props.projects.find((p) => p.id === projectId);
        return {
            key: projectId,
            label: project?.name ?? projectId,
            is_task: true,
            columns: project?.kanban_columns ?? [],
        };
    }),
);

const targetBeingCreated = ref<string | null>(null);

const allDocs = computed(() => {
    return Object.values(localKanbanData.value).flat() as ProjectDocument[];
});

// --- 2. AI PROCESSING — one listener per project, aggregated state ---
const aiInstances = props.projects.map((project) =>
    useAiProcessing(
        project.id,
        allDocs,
        targetBeingCreated,
        (incomingDoc: any) => {
            applyLocalUpdate(incomingDoc.id, incomingDoc);
        },
        () => {
            toast.success('Project Synced', {
                description: 'AI processing task completed.',
            });
        },
        (errorMessage) => {
            toast.error('AI Sync Error', { description: errorMessage });
        },
        removeLocalDocuments,
        ['kanbanData'],
    ),
);

const isAiProcessing = computed(() =>
    aiInstances.some((i) => i.isAiProcessing.value),
);
const aiProgress = computed(() =>
    Math.max(0, ...aiInstances.map((i) => i.aiProgress.value)),
);
const aiStatusMessage = computed(
    () =>
        aiInstances.find((i) => i.aiStatusMessage.value)?.aiStatusMessage
            .value ?? '',
);

// --- 3. UI METHODS & BREADCRUMBS ---
onKeyStroke('Escape', () => {
    searchQuery.value = '';
});

const breadcrumbs = computed(() => [
    {
        title: props.currentOrganization
            ? `Dashboard ${props.currentOrganization.name}`
            : 'Dashboard',
        href: '/dashboard',
    },
]);

// Whether there's at least one project row to render — not whether any task within it
// currently matches the search/priority filter. Columns should stay visible (and editable)
// even when empty, rather than the whole board disappearing behind a "no results" state.
const hasRows = computed(() => workflowRows.value.length > 0);

// Reprocess: look up the doc's project inline — no currentProject needed
const handleReprocess = async (id: string | number) => {
    const doc = allDocs.value.find((d) => d.id.toString() === id.toString());
    if (!doc) return;

    if (!confirm('Are you sure you want to generate the next workflow step?'))
        return;

    isSheetOpen.value = false;

    try {
        const response = await axios.post(
            `/projects/${doc.project_id}/documents/${doc.id}/reprocess`,
        );
        if (redirectIfLoggedOut(response)) return;
    } catch (error) {
        if (redirectIfSessionExpiredError(error)) return;

        toast.error('Failed to start reprocessing.');
    }
};

const handleTransition = async (
    id: string | number,
    payload: {
        toKey?: string;
        aiTemplateId: number;
        singleOutput?: boolean;
        projectTypeId?: string;
    },
) => {
    const doc = allDocs.value.find((d) => d.id.toString() === id.toString());
    if (!doc) return;

    isSheetOpen.value = false;

    try {
        const response = await axios.post(
            `/projects/${doc.project_id}/documents/${doc.id}/transition`,
            {
                to_key: payload.toKey,
                ai_template_id: payload.aiTemplateId,
                single_output: payload.singleOutput,
                project_type_id: payload.projectTypeId,
            },
        );
        if (redirectIfLoggedOut(response)) return;
    } catch (error) {
        if (redirectIfSessionExpiredError(error)) return;

        toast.error('Failed to start transition.');
    }
};

// Reprocessable types based on the selected document's project
const selectedDocumentProject = computed(
    () =>
        props.projects.find(
            (p) => p.id === (selectedDocument.value as any)?.project_id,
        ) ?? null,
);
const { reprocessableTypes } = useWorkflow();

const aiProcessedParentIds = computed(() => {
    const ids = new Set<string>();
    const docs = selectedDocumentProject.value?.documents ?? [];
    docs.forEach((d: ProjectDocument) => {
        if (d.parent_id) ids.add(d.parent_id);
    });
    return ids;
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-8 p-6">
            <div
                v-if="!projects.length"
                class="flex min-h-[40vh] flex-col items-center justify-center"
            >
                <div class="mb-4 rounded-full bg-gray-100 p-4">
                    <Coffee class="h-12 w-12 text-gray-400" />
                </div>
                <h2 class="text-xl font-bold text-gray-900">Coming Soon</h2>
                <p class="max-w-xs text-center text-gray-500">
                    You have not yet been assigned any projects or tasks.
                </p>
            </div>

            <template v-else>
                <AiProgressBar
                    :is-processing="isAiProcessing"
                    :progress="aiProgress"
                />

                <AiProcessingHeader
                    :is-processing="isAiProcessing"
                    :progress="aiProgress"
                    :message="aiStatusMessage"
                />

                <KanbanBoard
                    v-model:searchQuery="searchQuery"
                    v-model:selectedPriorities="selectedPriorities"
                    v-model:sortBy="sortBy"
                    v-model:selectedTagIds="selectedTagIds"
                    :available-tags="availableTags"
                    :projects-by-id="projectsById"
                    :assignee-options-by-project-id="assigneeOptionsByProjectId"
                    :has-rows="hasRows"
                    :workflow-rows="workflowRows"
                    :get-tasks-by-row-and-status="getTasksByRowAndStatus"
                    :get-task-count-by-row-and-status="getTaskCountByRowAndStatus"
                    :matches-filters="matchesFilters"
                    :on-drag-change="onDragChange"
                    :open-detail="openDetail"
                    :handle-create-new="handleCreateNew"
                    :update-attribute="
                        (docId, field, val) =>
                            updateAttribute(docId, { [field]: val }, 'Changes saved')
                    "
                    :update-tags="
                        (docId, categories) => updateTags(docId, categories)
                    "
                    :can-view-project-details="canViewProjectDetails"
                />
            </template>
        </div>

        <DocumentDetailSheet
            v-if="selectedDocument"
            :reprocessable-types="reprocessableTypes"
            :ai-processed-parent-ids="aiProcessedParentIds"
            v-model:open="isSheetOpen"
            :document="selectedDocument as ProjectDocument"
            @handle-reprocess="handleReprocess"
            @handle-transition="handleTransition"
            @update-attribute="
                (attr, val) =>
                    updateAttribute(
                        selectedDocument!.id,
                        { [attr]: val },
                        'Changes saved',
                    )
            "
        />
    </AppLayout>
</template>

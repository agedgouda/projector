<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AvailableRecordings from '@/pages/Projects/Partials/AvailableRecordings.vue';
import ImportDocumentOptions from '@/pages/Projects/Partials/ImportDocumentOptions.vue';
import ImportTaskListOptions from '@/pages/Projects/Partials/ImportTaskListOptions.vue';
import { Deferred, router } from '@inertiajs/vue3';
import { onKeyStroke } from '@vueuse/core';
import { PlusIcon, RefreshCw, ShieldAlert, Upload } from 'lucide-vue-next';
import { computed, onMounted, ref, useTemplateRef, watch } from 'vue';
import { toast } from 'vue-sonner';

import AppLayout from '@/layouts/AppLayout.vue';

import DocumentManager from '@/components/documents/DocumentManager.vue';
import { useKanbanBoard } from '@/composables/kanban/useKanbanBoard';
import { useAiProcessing } from '@/composables/useAiProcessing';
import { useDocumentActions } from '@/composables/useDocumentActions';
import { useEchoWatchdog } from '@/composables/useEchoWatchdog';
import { useTaskListImportProgress } from '@/composables/useTaskListImportProgress';
import {
    ACTION_ITEMS_KEY,
    INTAKE_KEY,
    reprocessDescription,
    useWorkflow,
} from '@/composables/useWorkflow';
import { setPersistentCookie } from '@/lib/utils';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import projectRoutes from '@/routes/projects/index';

// UI Components
import AiProcessingHeader from '@/components/AiProcessingHeader.vue';
import AiProgressBar from '@/components/AiProgressBar.vue';
import DocumentDetailSheet from '@/components/projects/DocumentDetailSheet.vue';
import KanbanBoard from '@/components/projects/KanbanBoard.vue';
import ProjectCalendar from '@/components/projects/ProjectCalendar.vue';
import ProjectSwitcher from '@/components/projects/ProjectSwitcher.vue';
import TaskReport from '@/components/reports/TaskReport.vue';
import ReprocessPromptModal from '@/components/ReprocessPromptModal.vue';

const props = defineProps<{
    projects: Project[];
    currentProject: (Project & { logo_url?: string | null }) | null;
    kanbanData: Record<string, ProjectDocument[]>;
    calendarItems: CalendarItem[];
    activeTab: string;
    clients: Client[];
    documentTypeCatalog: DocumentSchemaItem[];
    canManageTranscripts: boolean;
    canManageProject: boolean;
    meetingProvider: string | null;
    googlePickerConfigured: boolean;
    googleApiKey: string | null;
    googleAppId: string | null;
    recordingsData?: {
        recordings: Recording[];
        importedIds: string[];
        crossProjectImportedIds: string[];
        providerError: string | null;
        canManage: boolean;
    };
}>();

useEchoWatchdog(() => props.currentProject?.id);

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
    refreshComments,
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

// --- 2. AI PROCESSING (OBSERVER MODE) ---
const aiStatusMessageRef = ref('');
const activeTab = ref(props.activeTab);

// Each tab's "New Document" defaults the create form to whatever type someone creating from
// that tab almost always wants: Task from Tasks, Event from Campaign Calendar, Meeting Notes
// from Documentation (its rows are effectively all Meeting Notes once processed transcripts
// are hidden from the top level — see useDocumentTree.ts), Transcription from Recordings,
// since that's the tab for bringing in new raw source material. No default (the create form's
// own type picker) for any other tab.
const defaultTypeForCreate = computed<string | null>(() => {
    // Reports mirrors Tasks — it's the same underlying task data, just viewed as a report.
    if (activeTab.value === 'tasks' || activeTab.value === 'reports') return 'task';
    if (activeTab.value === 'calendar') return 'event';
    if (activeTab.value === 'hierarchy') return ACTION_ITEMS_KEY;
    if (activeTab.value === 'recordings') return INTAKE_KEY;
    return null;
});

// Mirrors defaultTypeForCreate's per-tab defaults in the "New ___" button's own label.
const createButtonLabel = computed(() => {
    if (activeTab.value === 'tasks' || activeTab.value === 'reports') return 'New Task';
    if (activeTab.value === 'calendar') return 'New Event';
    return 'New Document';
});

// The URL only gains an explicit `?tab=` when the user clicks a tab button (see updateTab()
// below) — on first load it can be absent even though `activeTab` (server-resolved from the
// query param, then a cookie, then a 'tasks' default) is already showing a specific tab. Various
// "open document" flows capture `window.location.href` as a `from` param so a document's "back"
// button returns to the exact tab the user was on; without this normalization, that capture can
// silently omit `?tab=`, so returning from a document would fall back to a stale cached tab
// instead of the one actually being viewed.
onMounted(() => {
    const url = new URL(window.location.href);
    if (url.searchParams.get('tab') !== activeTab.value) {
        url.searchParams.set('tab', activeTab.value);
        window.history.replaceState({}, '', url);
    }
});
const workflowRows = computed(() => {
    const columns = props.currentProject?.kanban_columns ?? [];

    return Object.keys(props.kanbanData).map((projectId) => {
        return { key: projectId, label: '', is_task: true, columns };
    });
});
const currentProjectDocumentSchema = computed(() =>
    props.documentTypeCatalog.filter((s) => s.is_task),
);

const { setDocToProcessing, setDocToTransitioning } = useDocumentActions(
    {
        project: props.currentProject as Project,
        documentSchema: currentProjectDocumentSchema.value,
    },
    aiStatusMessageRef,
    applyLocalUpdate,
);

const targetBeingCreated = ref<string | null>(null);
const isGenerating = ref(false);

const allDocs = computed(() => {
    return Object.values(localKanbanData.value).flat() as ProjectDocument[];
});

const projectIdForEcho = computed(
    () => props.currentProject?.id?.toString() ?? null,
);

const { aiStatusMessage, aiProgress, isAiProcessing } = useAiProcessing(
    projectIdForEcho.value ?? 'NO_PROJECT',
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
    ['currentProject', 'kanbanData'],
);

const { isImporting, importProgress, importMessage, startImporting } =
    useTaskListImportProgress(projectIdForEcho.value ?? 'NO_PROJECT');

// --- 3. UI METHODS & BREADCRUMBS ---
onKeyStroke('Escape', () => {
    searchQuery.value = '';
});

watch(aiStatusMessage, (val) => (aiStatusMessageRef.value = val));

const breadcrumbs = computed(() => [
    { title: 'Projects', href: '/projects' },
    { title: props.currentProject?.name ?? 'Select Project', href: '' },
]);

// Whether there's a project row to render — not whether any task within it currently
// matches the search/priority filter. Columns should stay visible (and editable) even when
// empty, rather than the whole board disappearing behind a "no results" state.
const hasRows = computed(() => workflowRows.value.length > 0);

const { reprocessableTypes } = useWorkflow();

const aiProcessedParentIds = computed(() => {
    const ids = new Set<string>();
    (props.currentProject?.documents ?? []).forEach((d: ProjectDocument) => {
        if (d.parent_id) ids.add(d.parent_id);
    });
    return ids;
});

const onImportQueued = () => {
    targetBeingCreated.value = 'transcript';
    aiProgress.value = 5;
    activeTab.value = 'hierarchy';
    setPersistentCookie('last_active_tab', 'hierarchy');
};

const reprocessConfirmDoc = ref<UIProjectDocument | null>(null);

const handleReprocess = (id: string | number) => {
    const stringId = id.toString();
    const doc = allDocs.value.find((d) => d.id.toString() === stringId) as
        | UIProjectDocument
        | undefined;

    if (!doc) return;

    isSheetOpen.value = false;

    // Nothing would be overwritten yet, so there's nothing to confirm.
    if (!aiProcessedParentIds.value.has(stringId)) {
        aiProgress.value = 5;
        void setDocToProcessing(doc);
        return;
    }

    reprocessConfirmDoc.value = doc;
};

const executeReprocess = (oneOffInstructions: string | null = null) => {
    const doc = reprocessConfirmDoc.value;
    reprocessConfirmDoc.value = null;
    if (!doc) return;

    aiProgress.value = 5;
    void setDocToProcessing(doc, oneOffInstructions);
};

const handleTransition = (
    id: string | number,
    payload: {
        toKey?: string;
        aiTemplateId: number;
        singleOutput?: boolean;
        projectTypeId?: string;
    },
) => {
    const stringId = id.toString();
    const doc = allDocs.value.find((d) => d.id.toString() === stringId) as
        | UIProjectDocument
        | undefined;

    if (!doc) return;

    aiProgress.value = 5;
    void setDocToTransitioning(doc, payload);
    isSheetOpen.value = false;
};

// recordingsData is a deferred prop backed by a live call to the meeting provider's API
// (see ProjectController::show()) — expensive enough that it's excluded from the reload
// every other tab switch already triggers here, so clicking Tasks/Calendar/etc. doesn't
// hit that API each time. Landing on Recordings specifically is the one exception: it's
// included so the list is checked fresh instead of showing whatever was cached from the
// last time this page loaded (see isRefreshingRecordings/refreshRecordings below for the
// same check triggered manually, without a tab switch).
//
// A direct/deep link straight to ?tab=recordings never calls updateTab() below (no click
// happened, so nothing sets this true) — Inertia's own automatic first-load fetch for a
// deferred prop still runs, but silently, with no button-spinner feedback, only the
// separate <Deferred> skeleton fallback. Initialized true for exactly that case (already on
// the Recordings tab, data not in yet) and cleared once recordingsData actually arrives, so
// the same "Checking…" state covers every path recordingsData can load through, not just
// the ones this component itself triggers.
const isRefreshingRecordings = ref(
    activeTab.value === 'recordings' && props.recordingsData === undefined,
);

watch(
    () => props.recordingsData,
    (data) => {
        if (data !== undefined) isRefreshingRecordings.value = false;
    },
);

const updateTab = (tab: string) => {
    activeTab.value = tab;
    setPersistentCookie('last_active_tab', tab);

    const isRecordingsTab = tab === 'recordings';
    if (isRecordingsTab) isRefreshingRecordings.value = true;

    router.get(
        window.location.pathname,
        {
            project: props.currentProject?.id,
            tab: tab,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            except: isRecordingsTab ? [] : ['recordingsData'],
            onFinish: () => {
                isRefreshingRecordings.value = false;
            },
        },
    );
};

const importTaskListOptionsRef = useTemplateRef('importTaskListOptionsRef');

// The Campaign Calendar's "Import Events" button lands here — opens the native file picker
// directly (ImportTaskListOptions is mounted via v-show, not v-if, regardless of which tab is
// active, so its ref is valid immediately) with the confirm modal pre-set to "Event List",
// without switching tabs — the Dialog renders via its own teleport, and the confirm/store flow
// already redirects to the Campaign Calendar on completion (see ImportTaskList::finish()), so
// there's nothing here that actually needs the Import tab itself to be showing.
const openEventImport = () => {
    importTaskListOptionsRef.value?.openEventImport();
};

// The Tasks tab's "Import Tasks" button — same mechanism as openEventImport() above, pre-set
// to "Task List" instead.
const openTaskImport = () => {
    importTaskListOptionsRef.value?.openTaskImport();
};

const tabActionsTarget = useTemplateRef('tabActionsTarget');

const importDocumentOptionsRef = useTemplateRef('importDocumentOptionsRef');

// The Documentation tab's "Import Document" button opens ImportDocumentOptions' modal, which
// holds the Google Docs / Word / Text options that used to live inline on the Import tab.
const openDocumentImport = () => {
    importDocumentOptionsRef.value?.openImportModal();
};

const refreshRecordings = () => {
    isRefreshingRecordings.value = true;
    router.reload({
        only: ['recordingsData'],
        onFinish: () => {
            isRefreshingRecordings.value = false;
        },
    });
};

const generateDeliverables = () => {
    if (!props.currentProject) return;
    router.post(
        projectRoutes.generate.url(props.currentProject.id),
        {},
        {
            onBefore: () => {
                isGenerating.value = true;
            },
            onFinish: () => {
                isGenerating.value = false;
            },
        },
    );
};

// --- 4. DOCUMENT MANAGER ACTIONS ---
const confirmDelete = (doc: ProjectDocument) => {
    if (!props.currentProject) return;

    if (confirm(`Are you sure you want to delete ${doc.name}?`)) {
        router.delete(
            projectDocumentsRoutes.destroy({
                project: props.currentProject.id,
                document: doc.id,
            }).url,
            {
                onSuccess: () => toast.success('Document deleted'),
                onError: () => toast.error('Failed to delete document'),
            },
        );
    }
};

const handleCreateNavigation = (projectId: string) => {
    router.visit(projectDocumentsRoutes.create({ project: projectId }).url, {
        data: {
            redirect: window.location.href,
            ...(defaultTypeForCreate.value
                ? { type: defaultTypeForCreate.value }
                : {}),
        },
    });
};

const isReactivateModalOpen = ref(false);
const isReactivating = ref(false);

const reactivateProject = () => {
    if (!props.currentProject) return;
    isReactivating.value = true;
    router.patch(
        projectRoutes.reactivate.url(String(props.currentProject.id)),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                isReactivateModalOpen.value = false;
                toast.success('Project reactivated');
            },
            onFinish: () => {
                isReactivating.value = false;
            },
        },
    );
};

watch(
    () => props.currentProject,
    (newProject) => {
        if (newProject?.id) {
            localStorage.setItem('last_project_id', newProject.id.toString());
        }
    },
    { immediate: true },
);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            v-if="!currentProject"
            class="flex min-h-[60vh] flex-col items-center justify-center p-6"
        >
            <div class="mb-4 rounded-full bg-gray-100 p-4">
                <ShieldAlert class="h-12 w-12 text-gray-400" />
            </div>
            <h2 class="text-xl font-bold text-gray-900">No Projects Found</h2>
            <p class="max-w-xs text-center text-gray-500">
                You haven't been assigned to any projects yet. Please contact
                your administrator.
            </p>
        </div>

        <div v-else class="w-full space-y-8 p-6">
            <AiProgressBar
                :is-processing="isAiProcessing"
                :progress="aiProgress"
            />

            <AiProcessingHeader
                :is-processing="isAiProcessing"
                :progress="aiProgress"
                :message="aiStatusMessage"
            />

            <AiProgressBar
                :is-processing="isImporting"
                :progress="importProgress"
            />

            <AiProcessingHeader
                title="Import Active"
                :is-processing="isImporting"
                :progress="importProgress"
                :message="importMessage"
            />

            <div
                class="flex w-full flex-col items-start justify-between gap-4 sm:flex-row sm:items-center"
            >
                <div class="flex w-full items-center gap-3 sm:w-auto">
                    <ProjectSwitcher
                        :projects="projects"
                        :current-project="currentProject"
                        :clients="clients"
                        @switch="(id) => router.get('/projects/' + id)"
                    />
                </div>

                <div class="flex w-full items-center gap-2 sm:w-auto">
                    <!-- Each tab teleports its own action button(s) here (see the
                         tabActionsTarget Teleports below) instead of this header knowing every
                         tab's buttons via a growing list of v-ifs. `display: contents` so the
                         teleported buttons lay out as direct flex items of this row, not as
                         children of this otherwise-empty wrapper. Bound by ref rather than a
                         "#tab-actions-target" CSS selector, and each Teleport is gated on
                         tabActionsTarget being set, because a selector (or an unguarded ref) can
                         resolve before this div is actually attached to the document on first
                         mount — Vue requires the target to already exist for a Teleport to find
                         it, and this div is rendered by the very same mount pass as the Teleports
                         targeting it, so without the guard the browser logs "Failed to locate
                         Teleport target" and the button silently never appears. -->
                    <div ref="tabActionsTarget" class="contents"></div>
                    <Button
                        v-if="!currentProject.inactive"
                        @click="handleCreateNavigation(currentProject.id)"
                        class="h-11 rounded-xl bg-projector-primary-600 px-6 font-bold whitespace-nowrap text-white hover:bg-projector-primary-700"
                    >
                        <PlusIcon class="mr-2 h-4 w-4" />
                        {{ createButtonLabel }}
                    </Button>
                    <Button
                        v-else
                        @click="isReactivateModalOpen = true"
                        class="h-11 rounded-xl bg-emerald-600 px-6 font-bold whitespace-nowrap text-white hover:bg-emerald-700"
                    >
                        <RefreshCw class="mr-2 h-4 w-4" /> Reactivate Project
                    </Button>
                </div>

                <Dialog
                    :open="isReactivateModalOpen"
                    @update:open="isReactivateModalOpen = false"
                >
                    <DialogContent class="sm:max-w-[425px]">
                        <DialogHeader>
                            <DialogTitle>Reactivate Project</DialogTitle>
                            <DialogDescription>
                                Are you sure you would like to reactivate this
                                project?
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter class="gap-2 sm:gap-4">
                            <Button
                                variant="outline"
                                @click="isReactivateModalOpen = false"
                                :disabled="isReactivating"
                            >
                                Cancel
                            </Button>
                            <Button
                                @click="reactivateProject"
                                :disabled="isReactivating"
                                class="bg-emerald-600 text-white hover:bg-emerald-700"
                            >
                                <RefreshCw
                                    v-if="isReactivating"
                                    class="mr-2 h-4 w-4 animate-spin"
                                />
                                Reactivate
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>

            <div
                class="mb-6 flex items-center border-b border-gray-200 dark:border-gray-700"
            >
                <button
                    v-for="tab in [
                        'tasks',
                        'reports',
                        'calendar',
                        'hierarchy',
                        'recordings',
                    ]"
                    :key="tab"
                    @click="updateTab(tab)"
                    :class="[
                        '-mb-[1px] border-b-2 px-8 py-4 text-[10px] font-black tracking-[0.2em] uppercase transition-all',
                        activeTab === tab
                            ? 'border-projector-primary-500 text-projector-primary-600'
                            : 'border-transparent text-gray-400 hover:text-gray-600',
                    ]"
                >
                    {{
                        tab === 'hierarchy'
                            ? 'Documentation'
                            : tab === 'recordings'
                              ? 'Transcripts'
                              : tab === 'calendar'
                                ? 'Campaign Calendar'
                                : tab === 'reports'
                                  ? 'Reports'
                                  : 'Tasks'
                    }}
                </button>
            </div>

            <div v-show="activeTab === 'tasks'">
                <!-- Reports shows this same button (see the Reports tab further down) since
                     it's the same underlying task data, just viewed as a report — the Teleport
                     itself lives here regardless of which of the two tabs is active. -->
                <Teleport
                    v-if="tabActionsTarget"
                    :to="tabActionsTarget"
                    :disabled="activeTab !== 'tasks' && activeTab !== 'reports'"
                >
                    <Button
                        v-if="!currentProject.inactive && canManageTranscripts"
                        variant="outline"
                        @click="openTaskImport"
                        class="h-11 rounded-xl px-6 font-bold whitespace-nowrap"
                    >
                        <Upload class="mr-2 h-4 w-4" />
                        Import Tasks
                    </Button>
                </Teleport>
                <KanbanBoard
                    v-model:searchQuery="searchQuery"
                    v-model:selectedPriorities="selectedPriorities"
                    v-model:sortBy="sortBy"
                    v-model:selectedTagIds="selectedTagIds"
                    :available-tags="availableTags"
                    :projects-by-id="projectsById"
                    :assignee-options-by-project-id="assigneeOptionsByProjectId"
                    :current-project="currentProject"
                    :has-rows="hasRows"
                    :workflow-rows="workflowRows"
                    :get-tasks-by-row-and-status="getTasksByRowAndStatus"
                    :get-task-count-by-row-and-status="
                        getTaskCountByRowAndStatus
                    "
                    :matches-filters="matchesFilters"
                    :on-drag-change="onDragChange"
                    :open-detail="openDetail"
                    :handle-create-new="handleCreateNew"
                    :update-attribute="
                        (docId, field, val) =>
                            updateAttribute(
                                docId,
                                { [field]: val },
                                'Changes saved',
                            )
                    "
                    :update-tags="
                        (docId, categories) => updateTags(docId, categories)
                    "
                    :can-manage-columns="canManageProject"
                />
            </div>

            <div v-show="activeTab === 'calendar'">
                <Teleport
                    v-if="tabActionsTarget"
                    :to="tabActionsTarget"
                    :disabled="activeTab !== 'calendar'"
                >
                    <Button
                        v-if="!currentProject.inactive && canManageTranscripts"
                        variant="outline"
                        @click="openEventImport"
                        class="h-11 rounded-xl px-6 font-bold whitespace-nowrap"
                    >
                        <Upload class="mr-2 h-4 w-4" />
                        Import Events
                    </Button>
                </Teleport>
                <ProjectCalendar
                    :project-id="currentProject.id"
                    :items="calendarItems"
                    :can-manage-imports="canManageTranscripts"
                    @import-events="openEventImport"
                />
            </div>

            <div v-show="activeTab === 'hierarchy'">
                <Teleport
                    v-if="tabActionsTarget"
                    :to="tabActionsTarget"
                    :disabled="activeTab !== 'hierarchy'"
                >
                    <Button
                        v-if="!currentProject.inactive && canManageTranscripts"
                        variant="outline"
                        @click="openDocumentImport"
                        class="h-11 rounded-xl px-6 font-bold whitespace-nowrap"
                    >
                        <Upload class="mr-2 h-4 w-4" />
                        Import Document
                    </Button>
                </Teleport>
                <ImportDocumentOptions
                    ref="importDocumentOptionsRef"
                    :project-id="currentProject!.id"
                    :can-manage="canManageTranscripts"
                    :google-picker-configured="googlePickerConfigured"
                    :google-api-key="googleApiKey"
                    :google-app-id="googleAppId"
                />
                <DocumentManager
                    :project="currentProject"
                    :live-documents="currentProject.documents"
                    :document-type-catalog="documentTypeCatalog"
                    :is-generating="isGenerating"
                    @confirm-delete="confirmDelete"
                    @generate="generateDeliverables"
                />
            </div>

            <div v-show="activeTab === 'recordings'">
                <div class="mb-4">
                    <div
                        v-if="!meetingProvider"
                        class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-gray-200 py-16 dark:border-gray-700"
                    >
                        <p class="text-sm font-bold text-gray-500">
                            No meeting provider configured
                        </p>
                        <p class="mt-1 text-xs text-gray-400">
                            Configure a provider in Organization Settings to
                            import recordings.
                        </p>
                    </div>

                    <template v-else>
                        <div class="mb-4 flex items-center justify-end gap-2">
                            <RefreshCw
                                v-if="isRefreshingRecordings"
                                class="h-3.5 w-3.5 animate-spin text-gray-400"
                            />
                            <button
                                type="button"
                                :disabled="isRefreshingRecordings"
                                class="text-[10px] font-black tracking-[0.2em] text-gray-400 uppercase transition-colors hover:text-gray-600 disabled:cursor-not-allowed disabled:opacity-50 dark:hover:text-gray-300"
                                @click="refreshRecordings"
                            >
                                {{
                                    isRefreshingRecordings
                                        ? 'Checking…'
                                        : 'Check for New Recordings'
                                }}
                            </button>
                        </div>

                        <Deferred data="recordingsData">
                            <template #fallback>
                                <div class="grid gap-0.5">
                                    <div
                                        v-for="i in 4"
                                        :key="i"
                                        class="flex h-12 animate-pulse items-center gap-3 px-2"
                                    >
                                        <div
                                            class="h-3.5 w-3.5 shrink-0 rounded bg-gray-100 dark:bg-gray-800"
                                        />
                                        <div
                                            class="flex flex-1 items-center gap-3"
                                        >
                                            <div
                                                class="h-3 w-40 rounded bg-gray-100 dark:bg-gray-800"
                                            />
                                            <div
                                                class="h-2.5 w-24 rounded bg-gray-100 dark:bg-gray-800"
                                            />
                                        </div>
                                        <div
                                            class="h-8 w-20 rounded-md bg-gray-100 dark:bg-gray-800"
                                        />
                                    </div>
                                </div>
                            </template>

                            <AvailableRecordings
                                :project-id="currentProject.id"
                                :recordings="recordingsData!.recordings"
                                :imported-ids="recordingsData!.importedIds"
                                :cross-project-imported-ids="
                                    recordingsData!.crossProjectImportedIds
                                "
                                :can-manage="recordingsData!.canManage"
                                :provider-error="recordingsData!.providerError"
                                @import-queued="onImportQueued"
                                @import-failed="targetBeingCreated = null"
                            />
                        </Deferred>
                    </template>
                </div>

                <ImportTaskListOptions
                    ref="importTaskListOptionsRef"
                    :project-id="currentProject!.id"
                    :can-manage="canManageTranscripts"
                    @started="startImporting"
                />
            </div>

            <div v-show="activeTab === 'reports'">
                <TaskReport :project="currentProject" />
            </div>
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
            @update-tags="(id, categories) => updateTags(id, categories)"
            @comments-changed="(id) => refreshComments(id)"
            @name-updated="(id, name) => applyLocalUpdate(id, { name })"
        />

        <ReprocessPromptModal
            :open="!!reprocessConfirmDoc"
            title="Reprocess Document?"
            :description="reprocessDescription(reprocessConfirmDoc)"
            @close="reprocessConfirmDoc = null"
            @confirm="executeReprocess"
        />
    </AppLayout>
</template>

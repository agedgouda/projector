<script setup lang="ts">
import ImportDocumentOptions from '@/pages/Projects/Partials/ImportDocumentOptions.vue';
import ImportTaskListOptions from '@/pages/Projects/Partials/ImportTaskListOptions.vue';
import AiProcessingHeader from '@/components/AiProcessingHeader.vue';
import AiProgressBar from '@/components/AiProgressBar.vue';
import IconTile from '@/components/IconTile.vue';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';
import { useTaskListImportProgress } from '@/composables/useTaskListImportProgress';
import projectRoutes from '@/routes/projects/index';
import axios from 'axios';
import { CalendarDays, FileText, ListChecks, Loader2, Sparkles } from 'lucide-vue-next';
import { computed, onMounted, ref, useTemplateRef } from 'vue';
import { toast } from 'vue-sonner';

interface WizardProject {
    id: string;
    name: string;
    logo_url: string | null;
}

interface ImportWizardContext {
    canManage: boolean;
    meetingProvider: string | null;
    documentTypeCatalog: DocumentSchemaItem[];
    // Trimmed to just the columns lib/documentTypes.ts's visibleDocumentTypeKeys() reads (see
    // ImportWizardController::projectContext()) — cast below to the fuller ProjectDocument[]
    // shape ImportDocumentOptions.vue's prop expects, since it too only reads those same columns.
    documents: Array<{ id: string; type: string; parent_id: string | null }>;
}

const props = defineProps<{
    project: WizardProject;
    googlePickerConfigured: boolean;
    googleApiKey: string | null;
    googleAppId: string | null;
}>();

const context = ref<ImportWizardContext | null>(null);
const loadError = ref(false);

const loadContext = async () => {
    loadError.value = false;
    context.value = null;
    try {
        const response = await axios.get<ImportWizardContext>(
            projectRoutes.importWizardContext.url(props.project.id),
        );
        context.value = response.data;
    } catch {
        loadError.value = true;
        toast.error('Could not load import options for this project.');
    }
};

onMounted(loadContext);

// ImportDocumentOptions.vue's `documents` prop is typed as the full ProjectDocument[] shape,
// but (like lib/documentTypes.ts's visibleDocumentTypeKeys(), the only thing it feeds) only
// ever reads id/type/parent_id off of it — the trimmed columns ImportWizardController's
// projectContext() actually sends.
const documentsForImportOptions = computed(() => context.value?.documents as unknown as ProjectDocument[]);

const { isImporting, importProgress, importMessage, startImporting } = useTaskListImportProgress(
    props.project.id,
);

const importDocumentOptionsRef = useTemplateRef('importDocumentOptionsRef');
const importTaskListOptionsRef = useTemplateRef('importTaskListOptionsRef');

const openDocumentImport = () => importDocumentOptionsRef.value?.openImportModal();
const openTaskImport = () => importTaskListOptionsRef.value?.openTaskImport();
const openEventImport = () => importTaskListOptionsRef.value?.openEventImport();
const openSmartImport = () => importTaskListOptionsRef.value?.openSmartImport();

const kinds = [
    { key: 'document', label: 'Document', description: 'Google Doc, Word/text file, or a meeting recording', icon: FileText, action: openDocumentImport },
    { key: 'task', label: 'Task List', description: 'Spreadsheet of tasks', icon: ListChecks, action: openTaskImport },
    { key: 'event', label: 'Event List', description: 'Spreadsheet of calendar events', icon: CalendarDays, action: openEventImport },
    { key: 'smart', label: 'Smart Import', description: 'AI-detected mix of tasks and events from a spreadsheet or text', icon: Sparkles, action: openSmartImport },
] as const;
</script>

<template>
    <div class="space-y-4">
        <AiProgressBar :is-processing="isImporting" :progress="importProgress" />
        <AiProcessingHeader title="Import Active" :is-processing="isImporting" :progress="importProgress" :message="importMessage" />

        <div v-if="!context && !loadError" class="flex flex-col items-center justify-center py-12">
            <Loader2 class="h-8 w-8 animate-spin text-projector-primary-500" />
        </div>

        <div v-else-if="loadError" class="rounded-2xl border-2 border-dashed border-gray-100 py-12 text-center dark:border-gray-800/50">
            <p class="font-bold text-gray-500">Could not load import options</p>
            <button type="button" class="mt-2 text-[10px] font-black tracking-widest text-projector-primary-600 uppercase" @click="loadContext">
                Try Again
            </button>
        </div>

        <template v-else-if="context">
            <div>
                <p class="mb-2 text-[10px] font-black tracking-widest text-gray-400 uppercase">
                    2. What Are You Importing?
                </p>

                <div class="grid gap-0.5">
                    <button
                        v-for="kind in kinds"
                        :key="kind.key"
                        type="button"
                        :class="['flex h-14 min-w-0 items-center gap-3 rounded-md px-2 text-left transition-colors', FLAT_ROW_HOVER]"
                        @click="kind.action"
                    >
                        <IconTile :icon="kind.icon" size="sm" tone="primary" />
                        <div class="min-w-0">
                            <div class="text-sm font-bold text-slate-900 dark:text-slate-100">
                                {{ kind.label }}
                            </div>
                            <div class="truncate text-xs text-slate-400">
                                {{ kind.description }}
                            </div>
                        </div>
                    </button>
                </div>
            </div>

            <ImportDocumentOptions
                ref="importDocumentOptionsRef"
                :project-id="project.id"
                :can-manage="context.canManage"
                :google-picker-configured="googlePickerConfigured"
                :google-api-key="googleApiKey"
                :google-app-id="googleAppId"
                :documents="documentsForImportOptions"
                :document-type-catalog="context.documentTypeCatalog"
                :meeting-provider="context.meetingProvider"
            />

            <ImportTaskListOptions
                ref="importTaskListOptionsRef"
                :project-id="project.id"
                :can-manage="context.canManage"
                @started="startImporting"
            />
        </template>
    </div>
</template>

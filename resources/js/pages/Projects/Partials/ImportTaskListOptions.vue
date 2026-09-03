<script setup lang="ts">
import ImportTaskListOptionsPanel from '@/components/recordings/ImportTaskListOptionsPanel.vue';
import ImportTransformationModal from '@/components/recordings/ImportTransformationModal.vue';
import TaskListImportConfirmModal from '@/components/recordings/TaskListImportConfirmModal.vue';
import taskListRoutes from '@/routes/projects/task-lists';
import axios from 'axios';
import { computed, ref, useTemplateRef } from 'vue';
import { toast } from 'vue-sonner';

const props = defineProps<{
    projectId: string;
    canManage: boolean;
}>();

const emit = defineEmits<{
    (e: 'started'): void;
}>();

const modalOpen = ref(false);

interface Analysis {
    headers: string[];
    rows: string[][];
    suggested_mapping: Record<string, string | null>;
    original_filename: string | null;
}

const analysis = ref<Analysis | null>(null);

// Populated instead of `analysis` when "Import Data" is given a plain-text source rather than a
// spreadsheet — read client-side (File.text()), no backend analyze() step needed since there are
// no headers/rows to parse out of it.
interface TextSource {
    text: string;
    originalFilename: string | null;
}

const textSource = ref<TextSource | null>(null);

// Which import flow the picked file should open once it's been read — 'task'/'event' open the
// single-type TaskListImportConfirmModal (pre-set to that list type; always a spreadsheet).
// 'smart' opens ImportTransformationModal instead, which can turn the same source into any mix
// of Tasks and Events at once, and — unlike 'task'/'event' — accepts either a spreadsheet or a
// plain-text document (see the accept prop on ImportTaskListOptionsPanel below); which of the
// two it got is recorded in smartSourceMode. Set just before triggering the picker (see
// openEventImport()/openTaskImport()/openSmartImport() below) and captured into activeMode — not
// read directly by either modal — so a cancelled file dialog, or a normal "Choose File" click
// straight after, isn't left defaulting to whichever mode was last triggered.
type ImportMode = 'task' | 'event' | 'smart';
const pendingMode = ref<ImportMode>('task');
const activeMode = ref<ImportMode>('task');
const smartSourceMode = ref<'spreadsheet' | 'text'>('spreadsheet');

// "Import Data" broadens the native picker to accept plain text too — everything else only ever
// offers spreadsheet files, matching ImportTaskListOptionsPanel's own default.
const pickerAccept = computed(() =>
    pendingMode.value === 'smart'
        ? '.csv,.xlsx,.xls,.txt,.md,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/plain,text/markdown'
        : undefined,
);

const isSpreadsheetFile = (file: File): boolean => {
    const name = file.name.toLowerCase();
    return (
        name.endsWith('.csv') || name.endsWith('.xlsx') || name.endsWith('.xls')
    );
};

const handleFilePicked = async (file: File) => {
    const mode = pendingMode.value;
    pendingMode.value = 'task';

    if (mode === 'smart' && !isSpreadsheetFile(file)) {
        try {
            smartSourceMode.value = 'text';
            textSource.value = {
                text: await file.text(),
                originalFilename: file.name,
            };
            analysis.value = null;
            activeMode.value = 'smart';
            modalOpen.value = true;
        } catch {
            toast.error(
                'Could not read that file. Please check it and try again.',
            );
        }
        return;
    }

    const formData = new FormData();
    formData.append('file', file);

    try {
        const response = await axios.post<Analysis>(
            taskListRoutes.analyze.url(props.projectId),
            formData,
        );
        smartSourceMode.value = 'spreadsheet';
        analysis.value = response.data;
        textSource.value = null;
        activeMode.value = mode;
        modalOpen.value = true;
    } catch (err) {
        const message =
            axios.isAxiosError(err) && err.response?.data?.message
                ? err.response.data.message
                : 'Could not read that spreadsheet. Please check the file and try again.';
        toast.error(message);
    }
};

const panelRef = useTemplateRef('panelRef');

// The Campaign Calendar's "Import Events" button and the Tasks tab's "Import Tasks" button
// (both in Projects/Show.vue) call these to skip straight to the native file picker with the
// confirm modal pre-set to "Event List" / "Task List", instead of leaving the user to find
// "Upload Task or Event List" and switch the toggle themselves. "Import Data" opens
// ImportTransformationModal instead — the same source, but able to become any mix of Tasks and
// Events (fresh AI-detected or a saved organization transformation), rather than one fixed type,
// and able to be a plain-text document instead of a spreadsheet.
defineExpose({
    openEventImport: () => {
        pendingMode.value = 'event';
        panelRef.value?.pickFile();
    },
    openTaskImport: () => {
        pendingMode.value = 'task';
        panelRef.value?.pickFile();
    },
    openSmartImport: () => {
        pendingMode.value = 'smart';
        panelRef.value?.pickFile();
    },
});
</script>

<template>
    <ImportTaskListOptionsPanel
        ref="panelRef"
        :can-manage="canManage"
        :accept="pickerAccept"
        @pick-file="handleFilePicked"
    />

    <!-- Live "X of Y" progress for a submitted import shows in the top-of-page
         AiProcessingHeader (see Projects/Show.vue) — @started fires the instant the modal's
         Import button is clicked, before the request even goes out, so that banner appears
         immediately; useTaskListImportProgress.ts picks up the rest from this project's
         TaskListImportProgress broadcasts. -->
    <TaskListImportConfirmModal
        v-if="analysis && activeMode !== 'smart'"
        :open="modalOpen"
        :project-id="projectId"
        :original-filename="analysis.original_filename"
        :headers="analysis.headers"
        :rows="analysis.rows"
        :suggested-mapping="analysis.suggested_mapping"
        :default-list-type="activeMode"
        @close="modalOpen = false"
        @started="emit('started')"
    />

    <!-- No live top-of-page progress banner here (unlike TaskListImportConfirmModal above) —
         a single import can queue several ImportTaskList passes at once, and the existing
         banner (useTaskListImportProgress.ts) only tracks one import's progress at a time, so
         this flow reports success via a toast instead and leaves each tab's own document list
         to show the results once ready. -->
    <ImportTransformationModal
        v-if="
            activeMode === 'smart' &&
            smartSourceMode === 'spreadsheet' &&
            analysis
        "
        :open="modalOpen"
        :project-id="projectId"
        :original-filename="analysis.original_filename"
        source-mode="spreadsheet"
        :headers="analysis.headers"
        :rows="analysis.rows"
        @close="modalOpen = false"
    />
    <ImportTransformationModal
        v-else-if="
            activeMode === 'smart' && smartSourceMode === 'text' && textSource
        "
        :open="modalOpen"
        :project-id="projectId"
        :original-filename="textSource.originalFilename"
        source-mode="text"
        :text="textSource.text"
        @close="modalOpen = false"
    />
</template>

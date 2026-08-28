<script setup lang="ts">
import ImportTaskListOptionsPanel from '@/components/recordings/ImportTaskListOptionsPanel.vue';
import TaskListImportConfirmModal from '@/components/recordings/TaskListImportConfirmModal.vue';
import taskListRoutes from '@/routes/projects/task-lists';
import axios from 'axios';
import { ref, useTemplateRef } from 'vue';
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

// Which list type the confirm modal should default to once the file being picked right now
// finishes analyzing. Set to 'event' just before triggering the picker (see openEventImport()
// below) and captured into activeListType — not read directly by the modal — so a cancelled
// file dialog, or a normal "Choose File" click straight after, isn't left defaulting to Event
// from a previous "Import Events" trigger.
const pendingListType = ref<'task' | 'event'>('task');
const activeListType = ref<'task' | 'event'>('task');

const handleFilePicked = async (file: File) => {
    const formData = new FormData();
    formData.append('file', file);

    try {
        const response = await axios.post<Analysis>(
            taskListRoutes.analyze.url(props.projectId),
            formData,
        );
        analysis.value = response.data;
        activeListType.value = pendingListType.value;
        pendingListType.value = 'task';
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
// "Upload Task or Event List" and switch the toggle themselves.
defineExpose({
    openEventImport: () => {
        pendingListType.value = 'event';
        panelRef.value?.pickFile();
    },
    openTaskImport: () => {
        pendingListType.value = 'task';
        panelRef.value?.pickFile();
    },
});
</script>

<template>
    <ImportTaskListOptionsPanel
        ref="panelRef"
        :can-manage="canManage"
        @pick-file="handleFilePicked"
    />

    <!-- Live "X of Y" progress for a submitted import shows in the top-of-page
         AiProcessingHeader (see Projects/Show.vue) — @started fires the instant the modal's
         Import button is clicked, before the request even goes out, so that banner appears
         immediately; useTaskListImportProgress.ts picks up the rest from this project's
         TaskListImportProgress broadcasts. -->
    <TaskListImportConfirmModal
        v-if="analysis"
        :open="modalOpen"
        :project-id="projectId"
        :original-filename="analysis.original_filename"
        :headers="analysis.headers"
        :rows="analysis.rows"
        :suggested-mapping="analysis.suggested_mapping"
        :default-list-type="activeListType"
        @close="modalOpen = false"
        @started="emit('started')"
    />
</template>

<script setup lang="ts">
import ImportTaskListOptionsPanel from '@/components/recordings/ImportTaskListOptionsPanel.vue';
import TaskListImportConfirmModal from '@/components/recordings/TaskListImportConfirmModal.vue';
import taskListRoutes from '@/routes/projects/task-lists';
import axios from 'axios';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

const props = defineProps<{
    projectId: string;
    canManage: boolean;
}>();

const isAnalyzing = ref<'csv' | 'xlsx' | null>(null);
const modalOpen = ref(false);

interface Analysis {
    headers: string[];
    rows: string[][];
    suggested_mapping: Record<string, string | null>;
    original_filename: string | null;
}

const analysis = ref<Analysis | null>(null);

const handleFilePicked = async (file: File, kind: 'csv' | 'xlsx') => {
    isAnalyzing.value = kind;

    const formData = new FormData();
    formData.append('file', file);

    try {
        const response = await axios.post<Analysis>(
            taskListRoutes.analyze.url(props.projectId),
            formData,
        );
        analysis.value = response.data;
        modalOpen.value = true;
    } catch (err) {
        const message =
            axios.isAxiosError(err) && err.response?.data?.message
                ? err.response.data.message
                : 'Could not read that spreadsheet. Please check the file and try again.';
        toast.error(message);
    } finally {
        isAnalyzing.value = null;
    }
};
</script>

<template>
    <ImportTaskListOptionsPanel
        :can-manage="canManage"
        :is-analyzing="isAnalyzing"
        @pick-file="handleFilePicked"
    />

    <TaskListImportConfirmModal
        v-if="analysis"
        :open="modalOpen"
        :project-id="projectId"
        :original-filename="analysis.original_filename"
        :headers="analysis.headers"
        :rows="analysis.rows"
        :suggested-mapping="analysis.suggested_mapping"
        @close="modalOpen = false"
        @imported="modalOpen = false"
    />
</template>

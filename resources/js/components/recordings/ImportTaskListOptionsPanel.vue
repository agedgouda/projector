<script setup lang="ts">
import { useTemplateRef } from 'vue';

// Purely presentational, mirroring ImportDocumentOptionsPanel.vue's split — the actual
// analyze request stays in the project-scoped wrapper (Projects/Partials/ImportTaskListOptions.vue),
// since that's what owns the modal/network state. This panel only owns the hidden file input
// and emits once a file has actually been picked. One input handles both CSV and Excel — the
// backend's analyze() endpoint already detects the format from the file itself (PhpSpreadsheet's
// IOFactory::createReaderForFile()), so the frontend never needs to know which one it is.
//
// There's no visible entry point here anymore — "Import Events" (Campaign Calendar tab) and
// "Import Tasks" (Tasks tab) in Projects/Show.vue are the only ways in, and they already know
// which list type they want, so this panel exists purely to host the hidden file input those
// buttons trigger via pickFile().
defineProps<{
    canManage: boolean;
}>();

const emit = defineEmits<{
    (e: 'pick-file', file: File): void;
}>();

const fileInput = useTemplateRef('fileInput');

const onFileChosen = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    emit('pick-file', file);
    input.value = '';
};

// Lets a parent (ultimately the Campaign Calendar's "Import Events" button, via
// ImportTaskListOptions.vue) open this hidden input's native file picker directly, without the
// user having to find and click "Choose File" themselves.
defineExpose({
    pickFile: () => fileInput.value?.click(),
});
</script>

<template>
    <input
        v-if="canManage"
        ref="fileInput"
        type="file"
        accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
        class="hidden"
        @change="onFileChosen($event)"
    />
</template>

<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';
import { FileSpreadsheet, Loader2 } from 'lucide-vue-next';
import { useTemplateRef } from 'vue';

// Purely presentational, mirroring ImportDocumentOptionsPanel.vue's split — the actual
// analyze request stays in the project-scoped wrapper (Projects/Partials/ImportTaskListOptions.vue),
// since that's what owns the modal/network state. This panel only owns the hidden file input
// and emits once a file has actually been picked. One input handles both CSV and Excel — the
// backend's analyze() endpoint already detects the format from the file itself (PhpSpreadsheet's
// IOFactory::createReaderForFile()), so the frontend never needs to know which one it is.
defineProps<{
    canManage: boolean;
    isAnalyzing: boolean;
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
    <section v-if="canManage" class="mb-8">
        <h2
            class="mb-4 text-[10px] font-black tracking-widest text-gray-400 uppercase"
        >
            Import a List
        </h2>

        <div class="grid gap-0.5">
            <div
                :class="[
                    'flex h-12 items-center gap-3 rounded-md px-2 transition-colors',
                    FLAT_ROW_HOVER,
                ]"
            >
                <div
                    class="flex h-4 w-4 shrink-0 items-center justify-center text-slate-400"
                >
                    <FileSpreadsheet class="h-3.5 w-3.5" />
                </div>
                <div class="min-w-0 flex-1">
                    <span
                        class="text-[13px] font-semibold text-slate-900 dark:text-slate-100"
                        >Upload Task or Event List (.csv, .xlsx)</span
                    >
                </div>
                <input
                    ref="fileInput"
                    type="file"
                    accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
                    class="hidden"
                    @change="onFileChosen($event)"
                />
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="isAnalyzing"
                    class="h-8 shrink-0 rounded-md px-3 text-[10px] font-black tracking-widest text-slate-600 uppercase dark:text-slate-300"
                    @click="fileInput?.click()"
                >
                    <Loader2
                        v-if="isAnalyzing"
                        class="mr-1.5 h-3 w-3 animate-spin"
                    />
                    {{ isAnalyzing ? 'Reading...' : 'Choose File' }}
                </Button>
            </div>
        </div>
    </section>
</template>

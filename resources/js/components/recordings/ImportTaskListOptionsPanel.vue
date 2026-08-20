<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';
import { FileSpreadsheet, Loader2 } from 'lucide-vue-next';
import { useTemplateRef } from 'vue';

// Purely presentational, mirroring ImportDocumentOptionsPanel.vue's split — the actual
// analyze request stays in the project-scoped wrapper (Projects/Partials/ImportTaskListOptions.vue),
// since that's what owns the modal/network state. This panel only owns the hidden file inputs
// and emits once a file has actually been picked.
defineProps<{
    canManage: boolean;
    isAnalyzing: 'csv' | 'xlsx' | null;
}>();

const emit = defineEmits<{
    (e: 'pick-file', file: File, kind: 'csv' | 'xlsx'): void;
}>();

const csvInput = useTemplateRef('csvInput');
const xlsxInput = useTemplateRef('xlsxInput');

const onFileChosen = (event: Event, kind: 'csv' | 'xlsx') => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    emit('pick-file', file, kind);
    input.value = '';
};
</script>

<template>
    <section v-if="canManage" class="mb-8">
        <h2
            class="mb-4 text-[10px] font-black tracking-widest text-gray-400 uppercase"
        >
            Import a Task List
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
                        >Upload CSV (.csv)</span
                    >
                </div>
                <input
                    ref="csvInput"
                    type="file"
                    accept=".csv,text/csv"
                    class="hidden"
                    @change="onFileChosen($event, 'csv')"
                />
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="isAnalyzing !== null"
                    class="h-8 shrink-0 rounded-md px-3 text-[10px] font-black tracking-widest text-slate-600 uppercase dark:text-slate-300"
                    @click="csvInput?.click()"
                >
                    <Loader2
                        v-if="isAnalyzing === 'csv'"
                        class="mr-1.5 h-3 w-3 animate-spin"
                    />
                    {{ isAnalyzing === 'csv' ? 'Reading...' : 'Choose File' }}
                </Button>
            </div>

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
                        >Upload Excel (.xlsx)</span
                    >
                </div>
                <input
                    ref="xlsxInput"
                    type="file"
                    accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                    class="hidden"
                    @change="onFileChosen($event, 'xlsx')"
                />
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="isAnalyzing !== null"
                    class="h-8 shrink-0 rounded-md px-3 text-[10px] font-black tracking-widest text-slate-600 uppercase dark:text-slate-300"
                    @click="xlsxInput?.click()"
                >
                    <Loader2
                        v-if="isAnalyzing === 'xlsx'"
                        class="mr-1.5 h-3 w-3 animate-spin"
                    />
                    {{ isAnalyzing === 'xlsx' ? 'Reading...' : 'Choose File' }}
                </Button>
            </div>
        </div>
    </section>
</template>

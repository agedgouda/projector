<script setup lang="ts">
import { ref, useTemplateRef } from 'vue';
import { FileStack, FileType, FileText, Loader2 } from 'lucide-vue-next';
import AiInstructionsPopover from '@/components/transcripts/AiInstructionsPopover.vue';
import { Button } from '@/components/ui/button';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';

// Purely presentational — the Google-connect-redirect dance and the actual route calls stay
// in each context's thin wrapper (Projects/Partials/ImportDocumentOptions.vue,
// Organizations/Partials/ImportDocumentOptions.vue), since those need that wrapper's own
// composable state. This panel only owns the three prompt textareas' local values and the
// hidden file inputs, emitting once the user has actually picked something.
defineProps<{
    canManage: boolean;
    googlePickerConfigured: boolean;
    isOpening: boolean;
    importingGoogleDoc: boolean;
    importingFile: 'docx' | 'txt' | null;
}>();

const emit = defineEmits<{
    (e: 'pick-google-doc', prompt: string): void;
    (e: 'pick-docx-file', file: File, prompt: string): void;
    (e: 'pick-txt-file', file: File, prompt: string): void;
}>();

const googleDocPrompt = ref('');
const docxPrompt = ref('');
const txtPrompt = ref('');

const docxInput = useTemplateRef('docxInput');
const txtInput = useTemplateRef('txtInput');

const onFileChosen = (event: Event, kind: 'docx' | 'txt') => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    if (kind === 'docx') {
        emit('pick-docx-file', file, docxPrompt.value);
    } else {
        emit('pick-txt-file', file, txtPrompt.value);
    }
    input.value = '';
};
</script>

<template>
    <section v-if="canManage" class="mb-8">
        <h2 class="mb-4 text-[10px] font-black tracking-widest text-gray-400 uppercase">
            Import a Document
        </h2>

        <div class="grid gap-0.5">
            <div v-if="googlePickerConfigured" :class="['flex items-center gap-3 h-12 px-2 rounded-md transition-colors', FLAT_ROW_HOVER]">
                <div class="flex h-4 w-4 shrink-0 items-center justify-center text-slate-400">
                    <FileStack class="h-3.5 w-3.5" />
                </div>
                <div class="min-w-0 flex-1">
                    <span class="text-[13px] font-semibold text-slate-900 dark:text-slate-100">Import from Google Docs</span>
                </div>
                <AiInstructionsPopover v-model="googleDocPrompt" />
                <Button
                    size="sm"
                    :disabled="isOpening || importingGoogleDoc"
                    class="shrink-0 rounded-md px-3 h-8 text-[10px] font-black uppercase tracking-widest bg-projector-primary-600 hover:bg-projector-primary-700 text-white"
                    @click="emit('pick-google-doc', googleDocPrompt)"
                >
                    <Loader2 v-if="isOpening || importingGoogleDoc" class="w-3 h-3 mr-1.5 animate-spin" />
                    {{ isOpening || importingGoogleDoc ? 'Importing...' : 'Choose Doc' }}
                </Button>
            </div>

            <div :class="['flex items-center gap-3 h-12 px-2 rounded-md transition-colors', FLAT_ROW_HOVER]">
                <div class="flex h-4 w-4 shrink-0 items-center justify-center text-slate-400">
                    <FileType class="h-3.5 w-3.5" />
                </div>
                <div class="min-w-0 flex-1">
                    <span class="text-[13px] font-semibold text-slate-900 dark:text-slate-100">Upload Word Document (.docx)</span>
                </div>
                <AiInstructionsPopover v-model="docxPrompt" />
                <input
                    ref="docxInput"
                    type="file"
                    accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                    class="hidden"
                    @change="onFileChosen($event, 'docx')"
                />
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="importingFile === 'docx'"
                    class="shrink-0 rounded-md px-3 h-8 text-[10px] font-black uppercase tracking-widest text-slate-600 dark:text-slate-300"
                    @click="docxInput?.click()"
                >
                    <Loader2 v-if="importingFile === 'docx'" class="w-3 h-3 mr-1.5 animate-spin" />
                    {{ importingFile === 'docx' ? 'Importing...' : 'Choose File' }}
                </Button>
            </div>

            <div :class="['flex items-center gap-3 h-12 px-2 rounded-md transition-colors', FLAT_ROW_HOVER]">
                <div class="flex h-4 w-4 shrink-0 items-center justify-center text-slate-400">
                    <FileText class="h-3.5 w-3.5" />
                </div>
                <div class="min-w-0 flex-1">
                    <span class="text-[13px] font-semibold text-slate-900 dark:text-slate-100">Upload Text File (.txt)</span>
                </div>
                <AiInstructionsPopover v-model="txtPrompt" />
                <input
                    ref="txtInput"
                    type="file"
                    accept=".txt,text/plain"
                    class="hidden"
                    @change="onFileChosen($event, 'txt')"
                />
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="importingFile === 'txt'"
                    class="shrink-0 rounded-md px-3 h-8 text-[10px] font-black uppercase tracking-widest text-slate-600 dark:text-slate-300"
                    @click="txtInput?.click()"
                >
                    <Loader2 v-if="importingFile === 'txt'" class="w-3 h-3 mr-1.5 animate-spin" />
                    {{ importingFile === 'txt' ? 'Importing...' : 'Choose File' }}
                </Button>
            </div>
        </div>
    </section>
</template>

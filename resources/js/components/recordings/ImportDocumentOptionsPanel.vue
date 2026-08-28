<script setup lang="ts">
import { ref, useTemplateRef } from 'vue';
import { FileStack, FileType, Loader2 } from 'lucide-vue-next';
import AiInstructionsPopover from '@/components/transcripts/AiInstructionsPopover.vue';
import { Button } from '@/components/ui/button';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';

// Purely presentational — the Google-connect-redirect dance and the actual route calls stay
// in each context's thin wrapper (Projects/Partials/ImportDocumentOptions.vue,
// Organizations/Partials/ImportDocumentOptions.vue), since those need that wrapper's own
// composable state. This panel only owns the prompt textareas' local values and the hidden
// file inputs, emitting once the user has actually picked something.
withDefaults(defineProps<{
    canManage: boolean;
    googlePickerConfigured: boolean;
    isOpening: boolean;
    importingGoogleDoc: boolean;
    importingFile: 'docx' | 'txt' | null;
    // Overridable per consumer — the project page's Import tab also folds its recordings
    // list into this same section (see Projects/Show.vue), where "Import a Document" reads
    // oddly for a group that also covers pulling in a meeting recording; every other
    // consumer keeps the original wording.
    heading?: string;
    // Same idea as heading — the project page tightens this to sit closer to the recordings
    // list that immediately follows it, since the two now read as one visual group; every
    // other consumer keeps the original, more generous section-to-section spacing.
    spacingClass?: string;
}>(), {
    heading: 'Import a Document',
    spacingClass: 'mb-8',
});

const emit = defineEmits<{
    (e: 'pick-google-doc', prompt: string): void;
    (e: 'pick-docx-file', file: File, prompt: string): void;
    (e: 'pick-txt-file', file: File, prompt: string): void;
}>();

const googleDocPrompt = ref('');
const filePrompt = ref('');

const fileInput = useTemplateRef('fileInput');

// The one hidden input accepts both .docx and .txt, so which of the two emits fires is decided
// here from the file itself (extension, falling back to MIME type) rather than by which button
// was clicked — there's only one button now.
const onFileChosen = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    const isDocx =
        file.name.toLowerCase().endsWith('.docx') ||
        file.type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    if (isDocx) {
        emit('pick-docx-file', file, filePrompt.value);
    } else {
        emit('pick-txt-file', file, filePrompt.value);
    }
    input.value = '';
};
</script>

<template>
    <section v-if="canManage" :class="spacingClass">
        <h2 v-if="heading" class="mb-4 text-[10px] font-black tracking-widest text-gray-400 uppercase">
            {{ heading }}
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
                    <span class="text-[13px] font-semibold text-slate-900 dark:text-slate-100">Upload Word or Text File (.docx, .txt)</span>
                </div>
                <AiInstructionsPopover v-model="filePrompt" />
                <input
                    ref="fileInput"
                    type="file"
                    accept=".docx,.txt,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain"
                    class="hidden"
                    @change="onFileChosen($event)"
                />
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="importingFile !== null"
                    class="shrink-0 rounded-md px-3 h-8 text-[10px] font-black uppercase tracking-widest text-slate-600 dark:text-slate-300"
                    @click="fileInput?.click()"
                >
                    <Loader2 v-if="importingFile !== null" class="w-3 h-3 mr-1.5 animate-spin" />
                    {{ importingFile !== null ? 'Importing...' : 'Choose File' }}
                </Button>
            </div>
        </div>
    </section>
</template>

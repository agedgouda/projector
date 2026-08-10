<script setup lang="ts">
import { ref, onMounted, useTemplateRef } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';
import { FileStack, FileType, FileText, Loader2 } from 'lucide-vue-next';
import AiInstructionsPopover from '@/components/transcripts/AiInstructionsPopover.vue';
import { Button } from '@/components/ui/button';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';
import { useGooglePicker } from '@/composables/transcripts/useGooglePicker';
import { useDocumentImportActions } from '@/composables/transcripts/useDocumentImportActions';
import transcriptRoutes from '@/routes/projects/transcripts/index';

const props = defineProps<{
    projectId: string;
    canManage: boolean;
    googlePickerConfigured: boolean;
    googleApiKey: string | null;
    googleAppId: string | null;
}>();

const { isOpening, openPicker } = useGooglePicker();
const { importingGoogleDoc, importGoogleDoc, importingFile, importFile } = useDocumentImportActions(props.projectId);

const googleDocPrompt = ref('');
const docxPrompt = ref('');
const txtPrompt = ref('');

// Unlike the export flows elsewhere in the app, picking a file can't survive a redirect —
// so this always fetches (and, if needed, connects) *before* ever opening the Picker, never
// mid-pick. On success it opens the Picker directly, so a fresh connect only ever means one
// extra round trip back to this exact state, not a resumed selection.
const startGoogleDocImport = async () => {
    try {
        const response = await axios.get<{ access_token: string }>(
            transcriptRoutes.googlePickerToken.url(props.projectId)
        );

        await openPicker({
            accessToken: response.data.access_token,
            apiKey: props.googleApiKey ?? '',
            appId: props.googleAppId ?? '',
            onPicked: (file) => importGoogleDoc(file, googleDocPrompt.value),
        });
    } catch (err) {
        if (axios.isAxiosError(err) && err.response?.status === 428 && err.response.data?.connect_url) {
            const returnUrl = new URL(window.location.href);
            returnUrl.searchParams.set('google_doc_import', '1');

            const connectUrl = new URL(err.response.data.connect_url);
            connectUrl.searchParams.set('return_to', returnUrl.pathname + returnUrl.search);

            window.location.href = connectUrl.toString();
            return;
        }
        toast.error('Could not connect to Google. Please try again.');
    }
};

onMounted(() => {
    if (new URLSearchParams(window.location.search).get('google_doc_import') === '1') {
        const url = new URL(window.location.href);
        url.searchParams.delete('google_doc_import');
        window.history.replaceState(window.history.state, '', url);
        void startGoogleDocImport();
    }
});

const docxInput = useTemplateRef('docxInput');
const txtInput = useTemplateRef('txtInput');

const onFileChosen = (event: Event, kind: 'docx' | 'txt', prompt: string) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    importFile(file, kind, prompt);
    input.value = '';
};
</script>

<template>
    <section v-if="canManage" class="mb-8">
        <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-4">
            Import a Document
        </h2>

        <div class="grid gap-0.5">
            <div v-if="googlePickerConfigured" :class="['flex items-center gap-3 h-12 px-2 rounded-md transition-colors', FLAT_ROW_HOVER]">
                <div class="w-4 h-4 flex items-center justify-center shrink-0 text-slate-400">
                    <FileStack class="w-3.5 h-3.5" />
                </div>
                <div class="flex-1 min-w-0">
                    <span class="font-semibold text-[13px] text-slate-900 dark:text-slate-100">Import from Google Docs</span>
                </div>
                <AiInstructionsPopover v-model="googleDocPrompt" />
                <Button
                    size="sm"
                    :disabled="isOpening || importingGoogleDoc"
                    class="shrink-0 bg-projector-primary-600 hover:bg-projector-primary-700 text-white rounded-md px-3 h-8 text-[10px] font-black uppercase tracking-widest"
                    @click="startGoogleDocImport"
                >
                    <Loader2 v-if="isOpening || importingGoogleDoc" class="w-3 h-3 mr-1.5 animate-spin" />
                    {{ isOpening || importingGoogleDoc ? 'Importing...' : 'Choose Doc' }}
                </Button>
            </div>

            <div :class="['flex items-center gap-3 h-12 px-2 rounded-md transition-colors', FLAT_ROW_HOVER]">
                <div class="w-4 h-4 flex items-center justify-center shrink-0 text-slate-400">
                    <FileType class="w-3.5 h-3.5" />
                </div>
                <div class="flex-1 min-w-0">
                    <span class="font-semibold text-[13px] text-slate-900 dark:text-slate-100">Upload Word Document (.docx)</span>
                </div>
                <AiInstructionsPopover v-model="docxPrompt" />
                <input
                    ref="docxInput"
                    type="file"
                    accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                    class="hidden"
                    @change="onFileChosen($event, 'docx', docxPrompt)"
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
                <div class="w-4 h-4 flex items-center justify-center shrink-0 text-slate-400">
                    <FileText class="w-3.5 h-3.5" />
                </div>
                <div class="flex-1 min-w-0">
                    <span class="font-semibold text-[13px] text-slate-900 dark:text-slate-100">Upload Text File (.txt)</span>
                </div>
                <AiInstructionsPopover v-model="txtPrompt" />
                <input
                    ref="txtInput"
                    type="file"
                    accept=".txt,text/plain"
                    class="hidden"
                    @change="onFileChosen($event, 'txt', txtPrompt)"
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

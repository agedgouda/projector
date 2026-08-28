<script setup lang="ts">
import { onMounted, ref } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';
import ImportDocumentOptionsPanel from '@/components/recordings/ImportDocumentOptionsPanel.vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
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

const modalOpen = ref(false);

// The Documentation tab's "Import Document" button (Projects/Show.vue) calls this to open the
// modal — see openTaskImport()/openEventImport() in ImportTaskListOptions.vue for the same
// pattern applied to task/event list import.
defineExpose({
    openImportModal: () => {
        modalOpen.value = true;
    },
});

// Unlike the export flows elsewhere in the app, picking a file can't survive a redirect —
// so this always fetches (and, if needed, connects) *before* ever opening the Picker, never
// mid-pick. On success it opens the Picker directly, so a fresh connect only ever means one
// extra round trip back to this exact state, not a resumed selection.
const startGoogleDocImport = async (prompt: string) => {
    try {
        const response = await axios.get<{ access_token: string }>(
            transcriptRoutes.googlePickerToken.url(props.projectId)
        );

        await openPicker({
            accessToken: response.data.access_token,
            apiKey: props.googleApiKey ?? '',
            appId: props.googleAppId ?? '',
            onPicked: (file) => importGoogleDoc(file, prompt),
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
        modalOpen.value = true;
        void startGoogleDocImport('');
    }
});
</script>

<template>
    <Dialog :open="modalOpen" @update:open="modalOpen = $event">
        <DialogContent class="sm:max-w-[560px]">
            <DialogHeader>
                <DialogTitle>Import a Document</DialogTitle>
            </DialogHeader>

            <ImportDocumentOptionsPanel
                heading=""
                spacing-class=""
                :can-manage="canManage"
                :google-picker-configured="googlePickerConfigured"
                :is-opening="isOpening"
                :importing-google-doc="importingGoogleDoc"
                :importing-file="importingFile"
                @pick-google-doc="startGoogleDocImport"
                @pick-docx-file="(file, prompt) => importFile(file, 'docx', prompt)"
                @pick-txt-file="(file, prompt) => importFile(file, 'txt', prompt)"
            />
        </DialogContent>
    </Dialog>
</template>

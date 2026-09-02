<script setup lang="ts">
import { onMounted } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';
import ImportDocumentOptionsPanel from '@/components/recordings/ImportDocumentOptionsPanel.vue';
import { useGooglePicker } from '@/composables/transcripts/useGooglePicker';
import { useOrgDocumentImportActions } from '@/composables/transcripts/useOrgDocumentImportActions';
import organizationRoutes from '@/routes/organizations/index';

const props = defineProps<{
    organizationId: string;
    canManage: boolean;
    googlePickerConfigured: boolean;
    googleApiKey: string | null;
    googleAppId: string | null;
}>();

const { isOpening, openPicker } = useGooglePicker();
const { importingGoogleDoc, importGoogleDoc, importingFile, importFile } = useOrgDocumentImportActions(props.organizationId);

// Mirrors Projects/Partials/ImportDocumentOptions.vue's startGoogleDocImport() — always
// fetches (and, if needed, connects) *before* ever opening the Picker, never mid-pick.
const startGoogleDocImport = async (prompt: string) => {
    try {
        const response = await axios.get<{ access_token: string }>(
            organizationRoutes.googlePickerToken(props.organizationId).url
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
        void startGoogleDocImport('');
    }
});
</script>

<template>
    <ImportDocumentOptionsPanel
        :can-manage="canManage"
        :google-picker-configured="googlePickerConfigured"
        :is-opening="isOpening"
        :importing-google-doc="importingGoogleDoc"
        :importing-file="importingFile"
        :show-prompt-popover="true"
        @pick-google-doc="startGoogleDocImport"
        @pick-docx-file="(file, prompt) => importFile(file, 'docx', prompt)"
        @pick-txt-file="(file, prompt) => importFile(file, 'txt', prompt)"
    />
</template>

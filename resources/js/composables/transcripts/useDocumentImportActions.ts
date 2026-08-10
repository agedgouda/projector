import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import transcriptRoutes from '@/routes/projects/transcripts/index';
import { type PickedGoogleDoc } from '@/composables/transcripts/useGooglePicker';

export function useDocumentImportActions(projectId: string) {
    const importingGoogleDoc = ref(false);
    const importingFile = ref<'docx' | 'txt' | null>(null);

    const importGoogleDoc = (file: PickedGoogleDoc, customPrompt?: string | null) => {
        importingGoogleDoc.value = true;

        router.post(transcriptRoutes.importGoogleDoc.url(projectId), {
            file_id: file.id,
            title: file.name,
            custom_prompt: customPrompt || null,
        }, {
            preserveScroll: true,
            onError: (errors) => {
                toast.error('Import failed', { description: Object.values(errors)[0] as string });
            },
            onFinish: () => { importingGoogleDoc.value = false; },
        });
    };

    const importFile = (file: File, kind: 'docx' | 'txt', customPrompt?: string | null) => {
        importingFile.value = kind;

        router.post(transcriptRoutes.importFile.url(projectId), {
            file,
            custom_prompt: customPrompt || null,
        }, {
            forceFormData: true,
            preserveScroll: true,
            onError: (errors) => {
                toast.error('Import failed', { description: Object.values(errors)[0] as string });
            },
            onFinish: () => { importingFile.value = null; },
        });
    };

    return { importingGoogleDoc, importGoogleDoc, importingFile, importFile };
}

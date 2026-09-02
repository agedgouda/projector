import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import transcriptRoutes from '@/routes/projects/transcripts/index';
import { type PickedGoogleDoc } from '@/composables/transcripts/useGooglePicker';

// What the picker chose the imported content should become — an existing catalog type (the
// intake type included, still the default), or a brand-new one the user is naming right now.
// Mirrors DocumentImportController::resolveDocumentType()'s own two branches; the backend is
// the one that actually validates/creates it, this is just how the choice travels there.
export type ImportTypeChoice = { type: string } | { newTypeLabel: string };

export function useDocumentImportActions(projectId: string) {
    const importingGoogleDoc = ref(false);
    const importingFile = ref<'docx' | 'txt' | null>(null);

    const importGoogleDoc = (file: PickedGoogleDoc, customPrompt: string | null | undefined, typeChoice: ImportTypeChoice) => {
        importingGoogleDoc.value = true;

        router.post(transcriptRoutes.importGoogleDoc.url(projectId), {
            file_id: file.id,
            title: file.name,
            custom_prompt: customPrompt || null,
            type: 'type' in typeChoice ? typeChoice.type : null,
            new_type_label: 'newTypeLabel' in typeChoice ? typeChoice.newTypeLabel : null,
        }, {
            preserveScroll: true,
            onError: (errors) => {
                toast.error('Import failed', { description: Object.values(errors)[0] as string });
            },
            onFinish: () => { importingGoogleDoc.value = false; },
        });
    };

    const importFile = (file: File, kind: 'docx' | 'txt', customPrompt: string | null | undefined, typeChoice: ImportTypeChoice) => {
        importingFile.value = kind;

        router.post(transcriptRoutes.importFile.url(projectId), {
            file,
            custom_prompt: customPrompt || null,
            type: 'type' in typeChoice ? typeChoice.type : null,
            new_type_label: 'newTypeLabel' in typeChoice ? typeChoice.newTypeLabel : null,
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

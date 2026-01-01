import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import axios, { AxiosError } from 'axios';
import { toast } from 'vue-sonner';

export function useDocumentActions(props: any) {
    const isUploadModalOpen = ref(false);
    const isEditModalOpen = ref(false);
    const editingDocumentId = ref<string | number | null>(null);

    const form = useForm({
        name: '',
        type: '',
        content: '',
    });

    const openUploadModal = (requirement?: any) => {
        form.reset();
        form.clearErrors();
        if (requirement) {
            form.type = requirement.key;
            form.name = `New ${requirement.label.replace(/s$/, '')}`;
        }
        isUploadModalOpen.value = true;
    };

    const openEditModal = (doc: ProjectDocument) => {
        form.clearErrors();
        editingDocumentId.value = doc.id;
        form.name = doc.name;
        form.type = doc.type;
        form.content = doc.content || '';
        isEditModalOpen.value = true;
    };

    const submitDocument = () => {
        const url = props.projectDocumentsRoutes.store.url(props.project.id);

        form.post(url, {
            preserveScroll: true,
            preserveState: true,
            // Optional: use this if you eventually add file uploads
            forceFormData: true,

            onSuccess: () => {
                isUploadModalOpen.value = false;
                form.reset();
                toast.success('Document Saved', {
                    description: 'AI processing has started...'
                });
            },

            onError: () => {
                // No need to pass 'errors' here;
                // Inertia handles form.errors automatically.
                isUploadModalOpen.value = true;
            }
        });
    };

    const updateDocument = async () => {
        form.processing = true;
        try {
            const url = props.projectDocumentsRoutes.update.url({
                project: props.project.id,
                document: editingDocumentId.value
            });
            await axios.post(url, { ...form.data(), _method: 'put' });
            isEditModalOpen.value = false;
            form.reset();
            router.reload({
                only: ['requirementStatus'],
                onFinish: () => { form.processing = false; toast.success('Document Updated'); }
            });
        } catch (err) {
            handleError(err, () => isEditModalOpen.value = true);
        }
    };

    const handleError = (err: any, reopenModal: () => void) => {
        const error = err as AxiosError<{ errors: any }>;
        form.processing = false;
        if (error.response?.status === 422) {
            form.errors = error.response.data.errors;
            reopenModal();
        }
    };

    return {
        form, isUploadModalOpen, isEditModalOpen,
        openUploadModal, openEditModal, submitDocument, updateDocument
    };
}

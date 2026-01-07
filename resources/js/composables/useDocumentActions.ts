import { ref, type Ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import axios, { AxiosError } from 'axios';

export function useDocumentActions(
        props: any,
        localRequirements: Ref<any[]>,
        aiStatusMessage: Ref<string>
    )
    {
    const isUploadModalOpen = ref(false);
    const isEditModalOpen = ref(false);
    const editingDocumentId = ref<string | number | null>(null);
    const targetBeingCreated = ref<string | number | null>(null);

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
            forceFormData: true,
            onBefore: () => {
                // Use props.project.type.workflow as requested
                const workflow = props.project?.type?.workflow || [];
                const step = workflow.find((s: any) => s.from_key === form.type);

                if (step) {
                    // 1. SET THE GHOST TARGET
                    targetBeingCreated.value = step.to_key;

                    const targetReq = props.requirementStatus.find((r: any) => r.key === step.to_key);
                    aiStatusMessage.value = `Creating ${targetReq?.plural_label || 'Deliverables'}...`;
                } else {
                    aiStatusMessage.value = 'Establishing Secure Uplink...';
                }
            },
            onSuccess: () => {
                isUploadModalOpen.value = false;
                form.reset();
                // Note: We do NOT reset targetBeingCreated here!
                // We wait for the Echo event to do that.
            },
            onError: () => {
                aiStatusMessage.value = '';
                targetBeingCreated.value = null; // Clear if request fails
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
                onFinish: () => { form.processing = false; }
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

const setDocToProcessing = (incomingId: string | number): void => {
    if (!incomingId) return;

    // Find the document type in our local state to determine the message
    let sourceDoc: any = null;
    localRequirements.value.forEach(group => {
        const found = group.documents.find((d: any) => String(d.id) === String(incomingId));
        if (found) sourceDoc = found;
    });

    if (sourceDoc) {
        const workflow = props.project?.type?.workflow || [];
        const step = workflow.find((s: any) => s.from_key === sourceDoc.type);
        const targetReq = props.requirementStatus.find((r: any) => r.key === step?.to_key);

        aiStatusMessage.value = targetReq
            ? `Generating ${targetReq.plural_label}...`
            : 'Initializing Neural Interface...';
    } else {
        aiStatusMessage.value = 'Initializing Neural Interface...';
    }

    localRequirements.value = localRequirements.value.map(group => ({
        ...group,
        documents: group.documents.map((d: any) =>
            String(d.id) === String(incomingId) ? { ...d, processed_at: null } : d
        )
    }));
};

    return {
        form, isUploadModalOpen, isEditModalOpen,
        openUploadModal, openEditModal, submitDocument, updateDocument, setDocToProcessing, targetBeingCreated
    };
}

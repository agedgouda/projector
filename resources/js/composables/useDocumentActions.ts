import { ref, type Ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import axios, { AxiosError } from 'axios';
import projectDocumentsRoutes from '@/routes/projects/documents/index';



export type UIProjectDocument = ProjectDocument & {
    processingError?: string | null;
    currentStatus?: string;
};

export function useDocumentActions(
    props: { project: Project; documentSchema?: DocumentSchemaItem[] },
    aiStatusMessage?: Ref<string>,
    updateDocState?: (id: string | number, data: Partial<UIProjectDocument>) => void
) {
    const isUploadModalOpen = ref(false);
    const isEditModalOpen = ref(false);
    const editingDocumentId = ref<string | null>(null);
    const targetBeingCreated = ref<string | number | null>(null);
    const internalAiMessage = aiStatusMessage ?? ref('');

    const form = useForm({
        id: undefined as string | undefined,
        name: '',
        type: '',
        content: '',
        metadata: {} as Record<string, any>,
        assignee_id: null as number | null,
    });

    const patchField = (docId: string, data: Record<string, any>) => {
        const url = projectDocumentsRoutes.update({ project: props.project.id, document: docId }).url;
        router.patch(url, data, {
            preserveScroll: true,
            onSuccess: () => {
                if (updateDocState) updateDocState(docId, data);
            },
        });
    };

    const updateField = (id: string, fieldName: string, rawValue: unknown) => {
        let normalizedValue: string | number | null = null;

        if (rawValue === 'unassigned' || rawValue == null) normalizedValue = null;
        else if (typeof rawValue === 'string' || typeof rawValue === 'number') normalizedValue = rawValue;
        else if (typeof rawValue === 'bigint') normalizedValue = Number(rawValue);
        else return;

        patchField(id, { [fieldName]: normalizedValue });
    };

    const safeJsonParse = (data: unknown) => {
        if (!data) return { criteria: [] };
        if (typeof data !== 'string') return data;
        try { return JSON.parse(data); } catch { return { criteria: [] }; }
    };

    const openUploadModal = (requirement?: any) => {
        form.reset();
        form.clearErrors();
        if (requirement) {
            form.type = requirement.key;
            form.name = `New ${requirement.label.replace(/s$/, '')}`;
        }
        isUploadModalOpen.value = true;
    };

    const openEditModal = (doc: UIProjectDocument) => {
        form.clearErrors();
        editingDocumentId.value = doc.id;
        form.name = doc.name;
        form.type = doc.type;
        form.content = doc.content || '';
        form.assignee_id = doc.assignee_id;
        form.metadata = safeJsonParse(doc.metadata);
        isEditModalOpen.value = true;
    };

    const submitDocument = () => {
        const url = projectDocumentsRoutes.store.url(props.project.id);
        form.post(url, {
            preserveScroll: true,
            preserveState: true,
            forceFormData: true,
            onBefore: () => {
                const workflow = props.project?.type?.workflow || [];
                const step = workflow.find((s: any) => s.from_key === form.type);
                if (step) {
                    targetBeingCreated.value = step.to_key;
                    const targetReq = props.documentSchema?.find((r: any) => r.key === step.to_key);
                    internalAiMessage.value = `Creating ${targetReq?.plural_label || 'Deliverables'}...`;
                } else {
                    internalAiMessage.value = 'Establishing Secure Uplink...';
                }
            },
            onSuccess: () => { isUploadModalOpen.value = false; form.reset(); },
            onError: () => { internalAiMessage.value = ''; targetBeingCreated.value = null; isUploadModalOpen.value = true; }
        });
    };

    const updateDocument = async (onSuccessCallback?: () => void) => {
        const docId = editingDocumentId.value;
        if (!docId) return;

        form.processing = true;
        try {
            const url = projectDocumentsRoutes.update.url({ project: props.project.id, document: docId });
            await axios.post(url, { ...form.data(), _method: 'put' });
            onSuccessCallback?.();
            isEditModalOpen.value = false;
            form.reset();
            router.reload({ only: ['requirementStatus'], onFinish: () => { form.processing = false; } });
        } catch (err) {
            const error = err as AxiosError<{ errors: any }>;
            form.processing = false;
            if (error.response?.status === 422) form.errors = error.response.data.errors;
        }
    };

    const setDocToProcessing = async (doc: UIProjectDocument) => {
        if (!doc) return;

        const actionText = doc.type === 'intake' ? 'regenerate User Stories' : 'generate next workflow step';
        if (!confirm(`Are you sure you want to ${actionText}?`)) return;

        // UI-only state
        doc.processingError = null;
        doc.currentStatus = 'Re-initializing AI...';

        try {
            const projectId = props.project.id;
            await axios.post(`/projects/${projectId}/documents/${doc.id}/reprocess`);
        } catch {
            const rollbackDate = new Date().toISOString();
            doc.processingError = 'Failed to start reprocessing.';
            doc.processed_at = rollbackDate;
        }
    };

    const navigateToDetails = (projectId: any, documentId: any, fromTab?: string) => {
        if (!projectId || !documentId) return;

        let url = projectDocumentsRoutes.show({
            project: String(projectId),
            document: String(documentId)
        }).url;

        // Append the tab directly to the URL string
        if (fromTab) {
            url += `?tab=${fromTab}`;
        }

        router.get(url);
    };

    return {
        form,
        isUploadModalOpen,
        isEditModalOpen,
        openUploadModal,
        openEditModal,
        submitDocument,
        editingDocumentId,
        updateDocument,
        setDocToProcessing,
        targetBeingCreated,
        patchField,
        updateField,
        safeJsonParse,
        navigateToDetails
    };
}

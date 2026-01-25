import { ref, type Ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import axios, { AxiosError } from 'axios';

export function useDocumentActions(
    props: {
        project: any;
        projectDocumentsRoutes: any;
        requirementStatus?: any[];
    },
    localRequirements: Ref<any[]>,
    aiStatusMessage: Ref<string>,
    updateDocState?: (id: string | number, data: Partial<ExtendedDocument>) => void
) {
    const isUploadModalOpen = ref(false);
    const isEditModalOpen = ref(false);
    const editingDocumentId = ref<string | number | null>(null);
    const targetBeingCreated = ref<string | number | null>(null);

    const form = useForm({
        id: undefined as string | undefined,
        name: '',
        type: '',
        content: '',
        metadata: {} as Record<string, any>,
        assignee_id: null as number | null,
    });

    /**
     * Patch a single field or set of fields instantly via Inertia.
     */
    const patchField = (docId: string | number, data: Record<string, any>) => {
        const url = props.projectDocumentsRoutes.update({
            project: props.project.id,
            document: docId
        }).url;

        router.patch(url, data, {
            preserveScroll: true,
            onSuccess: () => {
                if (updateDocState) {
                    updateDocState(docId, data);
                }
            },
        });
    };

    /**
     * The Centralized Normalizer
     * This is what your Sidebar calls via @change
     */
    const updateField = (id: string | number, fieldName: string, rawValue: unknown) => {
        let normalizedValue: string | number | null = null;

        if (rawValue === 'unassigned' || rawValue === null || rawValue === undefined) {
            normalizedValue = null;
        } else if (typeof rawValue === 'string' || typeof rawValue === 'number') {
            normalizedValue = rawValue;
        } else if (typeof rawValue === 'bigint') {
            normalizedValue = Number(rawValue);
        } else {
            console.warn(`[useDocumentActions] Could not normalize value for ${fieldName}`, rawValue);
            return;
        }

        patchField(id, { [fieldName]: normalizedValue });
    };

    /**
     * Helper for parsing metadata safely
     */
    const safeJsonParse = (data: unknown) => {
        if (!data) return { criteria: [] };
        if (typeof data !== 'string') return data;
        try {
            return JSON.parse(data);
        } catch (e) {
            console.warn('[useDocumentActions] Metadata parse failed', e);
            return { criteria: [] };
        }
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

    const openEditModal = (doc: ExtendedDocument) => {
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
        const url = props.projectDocumentsRoutes.store.url(props.project.id);

        form.post(url, {
            preserveScroll: true,
            preserveState: true,
            forceFormData: true,
            onBefore: () => {
                const workflow = props.project?.type?.workflow || [];
                const step = workflow.find((s: any) => s.from_key === form.type);

                if (step) {
                    targetBeingCreated.value = step.to_key;
                    const targetReq = props.requirementStatus?.find((r: any) => r.key === step.to_key);
                    aiStatusMessage.value = `Creating ${targetReq?.plural_label || 'Deliverables'}...`;
                } else {
                    aiStatusMessage.value = 'Establishing Secure Uplink...';
                }
            },
            onSuccess: () => {
                isUploadModalOpen.value = false;
                form.reset();
            },
            onError: () => {
                aiStatusMessage.value = '';
                targetBeingCreated.value = null;
                isUploadModalOpen.value = true;
            }
        });
    };

    const updateDocument = async (onSuccessCallback?: () => void) => {
        form.processing = true;

        try {
            const url = props.projectDocumentsRoutes.update.url({
                project: props.project.id,
                document: editingDocumentId.value
            });

            await axios.post(url, { ...form.data(), _method: 'put' });

            if (onSuccessCallback) onSuccessCallback();

            isEditModalOpen.value = false;
            form.reset();

            router.reload({
                only: ['requirementStatus'],
                onFinish: () => { form.processing = false; }
            });

        } catch (err) {
            handleError(err, () => {
                console.error('Axios call failed:', err);
            });
            form.processing = false;
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

    const setDocToProcessing = async (id: string | number) => {
        const doc = localRequirements.value
            .flatMap(group => group.documents)
            .find(d => d.id === id) as ExtendedDocument | undefined;

        if (!doc) return;

        const actionText = doc.type === 'intake'
            ? 'regenerate User Stories'
            : 'generate next workflow step';

        if (!confirm(`Are you sure you want to ${actionText}?`)) return;

        if (updateDocState) {
            updateDocState(id, {
                processed_at: null,
                processingError: null,
                currentStatus: 'Re-initializing AI...'
            });
        } else {
            doc.processed_at = null;
            doc.processingError = null;
        }

        try {
            const projectId = props.project.id;
            await axios.post(`/projects/${projectId}/documents/${id}/reprocess`);
        } catch (error) {
            console.error('AI Reprocess failed:', error);
            const rollbackDate = new Date().toISOString();

            if (updateDocState) {
                updateDocState(id, {
                    processed_at: rollbackDate,
                    processingError: 'Failed to start reprocessing.'
                });
            } else {
                doc.processed_at = rollbackDate;
            }
        }
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
        safeJsonParse
    };
}

import { saveDocument } from '@/lib/saveDocument';
import {
    redirectIfLoggedOut,
    redirectIfSessionExpiredError,
} from '@/lib/sessionExpiry';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import { router, useForm } from '@inertiajs/vue3';
import axios, { AxiosError } from 'axios';
import { ref, type Ref } from 'vue';

export type UIProjectDocument = ProjectDocument & {
    processingError?: string | null;
    currentStatus?: string;
};

export function useDocumentActions(
    props: { project: Project; documentSchema?: DocumentSchemaItem[] },
    aiStatusMessage?: Ref<string>,
    updateDocState?: (
        id: string | number,
        data: Partial<UIProjectDocument>,
    ) => void,
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

    /**
     * Saves fields on a document (see saveDocument()). Where the page keeps its own copy of the
     * documents (`updateDocState`) the saved record goes there; a page that only has what the
     * server sent it (Documents/Show) is given the fresh record by re-reading its `item`.
     */
    const saveFields = async (
        docId: string,
        fields: Record<string, unknown>,
    ) => {
        const saved = await saveDocument(props.project.id, docId, fields, {
            apply: (values) => updateDocState?.(docId, values),
        });

        if (saved && !updateDocState) {
            router.reload({ only: ['item'] });
        }
    };

    const updateField = (id: string, fieldName: string, value: unknown) =>
        saveFields(id, { [fieldName]: value });

    // Reassigns a task's home board — a separate endpoint (DocumentController::move()) since
    // moving between boards has its own family/matching-columns validation.
    const moveToBoard = (
        docId: string,
        targetProjectId: string,
        onError?: (message: string) => void,
    ) => {
        const url = projectDocumentsRoutes.move({
            project: props.project.id,
            document: docId,
        }).url;
        router.patch(
            url,
            { project_id: targetProjectId },
            {
                preserveScroll: true,
                onError: (errors) =>
                    onError?.(
                        Object.values(errors)[0] ?? 'Could not move this task.',
                    ),
            },
        );
    };

    // Sets the complete list of tags on a task — sync semantics (send the full desired set,
    // not a single add/remove).
    const updateTags = (docId: string, categories: CategoryDef[]) =>
        saveFields(docId, { category_ids: categories.map((c) => c.id) });

    const safeJsonParse = (data: unknown) => {
        if (!data) return { criteria: [] };
        if (typeof data !== 'string') return data;
        try {
            return JSON.parse(data);
        } catch {
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

    const openEditModal = (doc: UIProjectDocument) => {
        form.clearErrors();
        editingDocumentId.value = String(doc.id);
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
                internalAiMessage.value = 'Establishing Secure Uplink...';
            },
            onSuccess: () => {
                isUploadModalOpen.value = false;
                form.reset();
            },
            onError: () => {
                internalAiMessage.value = '';
                targetBeingCreated.value = null;
                isUploadModalOpen.value = true;
            },
        });
    };

    const updateDocument = async (onSuccessCallback?: () => void) => {
        const docId = editingDocumentId.value;
        if (!docId) return;

        form.processing = true;
        try {
            const url = projectDocumentsRoutes.update.url({
                project: props.project.id,
                document: docId,
            });
            const response = await axios.post(url, {
                ...form.data(),
                _method: 'put',
            });
            if (redirectIfLoggedOut(response)) return;

            onSuccessCallback?.();
            isEditModalOpen.value = false;
            form.reset();
            router.reload({
                only: ['requirementStatus'],
                onFinish: () => {
                    form.processing = false;
                },
            });
        } catch (err) {
            if (redirectIfSessionExpiredError(err)) return;

            const error = err as AxiosError<{ errors: any }>;
            form.processing = false;
            if (error.response?.status === 422)
                form.errors = error.response.data.errors;
        }
    };

    const setDocToProcessing = async (
        doc: UIProjectDocument,
        oneOffInstructions: string | null = null,
    ) => {
        if (!doc) return;

        // UI-only state, set synchronously (before the network round trip) so
        // isAiProcessing flips true — and the progress bar/header appear — the instant
        // the button is pressed rather than waiting on the server's response.
        doc.processingError = null;
        doc.currentStatus = 'Re-initializing AI...';
        doc.processed_at = null;

        try {
            const projectId = props.project.id;
            const response = await axios.post(
                `/projects/${projectId}/documents/${doc.id}/reprocess`,
                {
                    one_off_instructions: oneOffInstructions,
                },
            );
            if (redirectIfLoggedOut(response)) return;
        } catch (error) {
            if (redirectIfSessionExpiredError(error)) return;

            const rollbackDate = new Date().toISOString();
            doc.processingError = 'Failed to start reprocessing.';
            doc.processed_at = rollbackDate;
        }
    };

    const setDocToTransitioning = async (
        doc: UIProjectDocument,
        payload: {
            toKey?: string;
            aiTemplateId: number;
            singleOutput?: boolean;
            projectTypeId?: string;
        },
    ) => {
        if (!doc) return;

        // UI-only state, set synchronously — see setDocToProcessing above.
        doc.processingError = null;
        doc.currentStatus = 'Running transition...';
        doc.processed_at = null;

        try {
            const projectId = props.project.id;
            const response = await axios.post(
                `/projects/${projectId}/documents/${doc.id}/transition`,
                {
                    to_key: payload.toKey,
                    ai_template_id: payload.aiTemplateId,
                    single_output: payload.singleOutput,
                    project_type_id: payload.projectTypeId,
                },
            );
            if (redirectIfLoggedOut(response)) return;
        } catch (error) {
            if (redirectIfSessionExpiredError(error)) return;

            const rollbackDate = new Date().toISOString();
            doc.processingError = 'Failed to start transition.';
            doc.processed_at = rollbackDate;
        }
    };

    const navigateToDetails = (projectId: any, documentId: any) => {
        if (!projectId || !documentId) return;

        const baseUrl = projectDocumentsRoutes.show({
            project: String(projectId),
            document: String(documentId),
        }).url;

        // Capture the current URL (which already has ?tab=... and ?expanded=... via replaceState)
        // and pass it as the `from` param so the back button returns here exactly.
        const from = window.location.href;

        // Save scroll position so it can be restored on return.
        sessionStorage.setItem(
            `doc_scroll_${projectId}`,
            String(Math.round(window.scrollY)),
        );

        const url = `${baseUrl}?from=${encodeURIComponent(from)}`;

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
        setDocToTransitioning,
        targetBeingCreated,
        updateField,
        moveToBoard,
        updateTags,
        safeJsonParse,
        navigateToDetails,
    };
}

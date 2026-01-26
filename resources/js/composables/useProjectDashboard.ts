import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';

import projectRoutes from '@/routes/projects/index';


export function useProjectDashboard(props: { project: Project, origin?: string | null }) {
    const isDeleteModalOpen = ref(false);
    const isDeleting = ref(false);
    const documentToDelete = ref<any>(null);
    const getInitialTab = () => {
        if (typeof window !== 'undefined') {
            const params = new URLSearchParams(window.location.search);
            return params.get('tab') || 'hierarchy';
        }
        return 'hierarchy';
    };
    const activeTab = ref(getInitialTab());
    const isEditModalOpen = ref(false);

    const requirementStatus = computed(() => {
        const schema = (props.project.type.document_schema as DocumentSchemaItem[]) || [];
        const allDocs = (props.project.documents as ProjectDocument[]) || [];
        return schema.map((req) => {
            const matchingDocs = allDocs.filter((doc) => doc.type === req.key);
            return { ...req, documents: matchingDocs, isUploaded: matchingDocs.length > 0 };
        });
    });

    const canGenerate = computed(() =>
        requirementStatus.value.filter(r => r.required).every(r => r.isUploaded)
    );

    const confirmDelete = (doc?: any) => {
        documentToDelete.value = doc || null;
        isDeleteModalOpen.value = true;
    };

    const deleteAction = (routes: any) => {
        isDeleting.value = true;
        const url = documentToDelete.value
            ? routes.documents.destroy.url({ project: props.project.id, document: documentToDelete.value.id })
            : routes.projects.destroy.url({ project: props.project.id });

        router.delete(url, {
            onSuccess: () => { isDeleteModalOpen.value = false; documentToDelete.value = null; },
            onFinish: () => isDeleting.value = false,
            preserveScroll: true
        });
    };

    const handleBack = () => {
        if (props.origin === 'index') {
            router.visit(projectRoutes.index.url());
        } else {
            router.visit(`/clients/${props.project.client_id}`);
        }
    };

    const backLabel = computed(() => {
        return props.origin === 'index' ? 'Back to Projects' : 'Back to Client';
    });


    return {    requirementStatus,
                canGenerate,
                isDeleteModalOpen,
                isDeleting,
                documentToDelete,
                confirmDelete,
                deleteAction,
                activeTab,
                isEditModalOpen,
                handleBack,
                backLabel,
            };
}

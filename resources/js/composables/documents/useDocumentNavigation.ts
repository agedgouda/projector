// resources/js/composables/documents/useDocumentNavigation.ts
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import projectRoutes from '@/routes/projects/index';
import { dashboard } from '@/routes';

export function useDocumentNavigation(project: Project, item?: Partial<ExtendedDocument> | null) {
    const getReturnUrl = () => {
        const params = new URLSearchParams(window.location.search);
        const returnTab = params.get('tab') || 'hierarchy';
        return `${dashboard().url}?tab=${returnTab}`;
    };

    const breadcrumbs = computed(() => [
        { title: 'Projects', href: projectRoutes.index.url() },
        { title: project.name, href: getReturnUrl() },
        {
            // Fallback to "New Document" if the name is empty or item is null
            title: item?.name || 'New Document',
            href: ''
        }
    ]);

    const handleBack = () => router.visit(getReturnUrl());

    return {
        breadcrumbs,
        handleBack
    };
}

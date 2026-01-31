// resources/js/composables/documents/useDocumentNavigation.ts
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import projectRoutes from '@/routes/projects/index';

export function useDocumentNavigation(project: Project, item: ExtendedDocument) {
    const getReturnUrl = () => {
        const params = new URLSearchParams(window.location.search);
        const returnTab = params.get('tab') || 'hierarchy';
        return `${projectRoutes.show(project.id).url}?tab=${returnTab}`;
    };

    const breadcrumbs = computed(() => [
        { title: 'Projects', href: projectRoutes.index.url() },
        { title: project.name, href: getReturnUrl() },
        { title: item.name, href: '' }
    ]);

    const handleBack = () => router.visit(getReturnUrl());

    return {
        breadcrumbs,
        handleBack
    };
}

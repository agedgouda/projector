import { computed } from 'vue';
//import { router } from '@inertiajs/vue3';
import projectRoutes from '@/routes/projects/index';
import { dashboard } from '@/routes';

export function useDocumentNavigation(project: Project, item?: Partial<ExtendedDocument> | null) {
    const getReturnUrl = () => {
        const params = new URLSearchParams(window.location.search);
        const from = params.get('from');
        if (from) return from;
        const returnTab = params.get('tab') || 'hierarchy';
        return `${dashboard().url}?tab=${returnTab}`;
    };

    const breadcrumbs = computed(() => {
        const returnUrl = getReturnUrl();
        const fromUrl = new URLSearchParams(window.location.search).get('from');
        const isDashboard = fromUrl && new URL(fromUrl).pathname === dashboard().url;

        if (isDashboard) {
            return [
                { title: 'Dashboard', href: returnUrl },
                { title: item?.name || 'New Document', href: '' },
            ];
        }

        return [
            { title: 'Projects', href: projectRoutes.index.url() },
            { title: project.name, href: returnUrl },
            { title: item?.name || 'New Document', href: '' },
        ];
    });

    const handleBack = () => {
        const hasHistory = window.appHasHistory || (window.history.state && window.history.state.back);

        if (hasHistory && window.history.length > 1) {
            window.history.back();
        } else {
            // Force navigate to the logical parent (Project Dashboard)
            const url = getReturnUrl();
            // Use window.location for a hard reset if router.visit fails you
            window.location.href = url;
        }
    };

    return {
        breadcrumbs,
        handleBack
    };
}

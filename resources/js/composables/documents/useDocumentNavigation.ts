import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import projectRoutes from '@/routes/projects/index';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import { dashboard } from '@/routes';

type AncestorDoc = { id: string | number; name: string; parent?: AncestorDoc | null };

export function useDocumentNavigation(project: Project, item?: (Partial<ExtendedDocument> & { parent?: AncestorDoc | null }) | null) {
    const getReturnUrl = () => {
        const params = new URLSearchParams(window.location.search);
        const from = params.get('from');
        if (from) return from;
        const returnTab = params.get('tab') || 'hierarchy';
        return `${projectRoutes.show.url(project.id)}?tab=${returnTab}`;
    };

    const getAncestorUrl = (ancestorId: string | number) => {
        const baseUrl = projectDocumentsRoutes.show({
            project: String(project.id),
            document: String(ancestorId),
        }).url;
        const from = new URLSearchParams(window.location.search).get('from');
        return from ? `${baseUrl}?from=${encodeURIComponent(from)}` : baseUrl;
    };

    const buildAncestors = (): { id: string | number; name: string }[] => {
        const chain: { id: string | number; name: string }[] = [];
        let current = item?.parent ?? null;
        while (current) {
            chain.unshift({ id: current.id, name: current.name });
            current = current.parent ?? null;
        }
        return chain;
    };

    const breadcrumbs = computed(() => {
        const returnUrl = getReturnUrl();
        const fromUrl = new URLSearchParams(window.location.search).get('from');
        const isDashboard = fromUrl && new URL(fromUrl).pathname === dashboard().url;
        const ancestors = buildAncestors();

        const ancestorCrumbs = ancestors.map(a => ({
            title: a.name,
            href: getAncestorUrl(a.id),
        }));

        if (isDashboard) {
            return [
                { title: 'Dashboard', href: returnUrl },
                ...ancestorCrumbs,
                { title: item?.name || 'New Document', href: '' },
            ];
        }

        return [
            { title: 'Projects', href: projectRoutes.index.url() },
            { title: project.name, href: returnUrl },
            ...ancestorCrumbs,
            { title: item?.name || 'New Document', href: '' },
        ];
    });

    const handleBack = () => {
        router.visit(getReturnUrl());
    };

    return {
        breadcrumbs,
        handleBack
    };
}

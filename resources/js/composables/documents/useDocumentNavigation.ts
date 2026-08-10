import { dashboard } from '@/routes';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import projectRoutes from '@/routes/projects/index';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

type AncestorDoc = {
    id: string | number;
    name: string;
    parent?: AncestorDoc | null;
};

export function useDocumentNavigation(
    project: Project,
    item?: (Partial<ExtendedDocument> & { parent?: AncestorDoc | null }) | null,
) {
    // "Back" from a document returns to wherever the user actually came from — the literal
    // previous page, whether that's a project tab or another document. That page's URL
    // travels in the `from` query param (see the various "open document" call sites, and
    // currentUrlAsFrom() below, which keep it pointed at the immediately preceding page on
    // every hop); the `tab` fallback below only matters if `from` is somehow missing, e.g. a
    // document opened directly via a bookmarked/shared link.
    const getReturnUrl = () => {
        const params = new URLSearchParams(window.location.search);
        const from = params.get('from');
        if (from) return from;
        const returnTab = params.get('tab') || 'hierarchy';
        return `${projectRoutes.show.url(project.id)}?tab=${returnTab}`;
    };

    // The page being navigated away from becomes the new `from` — never the `from` that page
    // itself arrived with. That keeps `from` pointed at the literal previous page at every
    // hop, so "back" is correct whether the user drilled down step-by-step or jumped straight
    // to a nested document from the project's tree/list.
    const currentUrlAsFrom = (): string => {
        const url = new URL(window.location.href);
        url.searchParams.delete('from');
        return url.toString();
    };

    const getAncestorUrl = (ancestorId: string | number) => {
        const baseUrl = projectDocumentsRoutes.show({
            project: String(project.id),
            document: String(ancestorId),
        }).url;
        return `${baseUrl}?from=${encodeURIComponent(currentUrlAsFrom())}`;
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
        const isDashboard =
            fromUrl && new URL(fromUrl).pathname === dashboard().url;
        const ancestors = buildAncestors();

        const ancestorCrumbs = ancestors.map((a) => ({
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
        handleBack,
    };
}

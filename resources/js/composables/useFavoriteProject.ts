import {
    destroy as destroyFavorite,
    store as storeFavorite,
} from '@/actions/App/Http/Controllers/ProjectFavoriteController';
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Backs every place a project can be favorited/unfavorited — ProjectFolio.vue's rows,
// ProjectSwitcher.vue's trigger and dropdown rows — so the check against the shared
// favoriteProjects prop (see HandleInertiaRequests::share(), which spans every org the user
// belongs to) only lives in one place.
export function useFavoriteProjectIds() {
    const page = usePage();

    return computed(
        () =>
            new Set(
                (
                    (page.props as any).favoriteProjects as Array<{
                        id: string;
                    }>
                ).map((favorite) => favorite.id),
            ),
    );
}

export function toggleProjectFavorite(
    projectId: string,
    isFavorited: boolean,
    options?: { onFinish?: () => void },
) {
    const routerOptions = {
        preserveScroll: true,
        preserveState: true,
        onFinish: options?.onFinish,
    } as const;

    if (isFavorited) {
        router.delete(destroyFavorite.url(projectId), routerOptions);
    } else {
        router.post(storeFavorite.url(projectId), {}, routerOptions);
    }
}

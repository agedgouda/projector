import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Ensure the 'export' keyword is right here:
export function useKanbanPermissions() {
    const page = usePage<AppPageProps>();

    const isAdmin = computed(() =>
        page.props.auth?.user?.roles?.some((role: any) => role.name === 'admin') ?? false
    );

    return { isAdmin };
}

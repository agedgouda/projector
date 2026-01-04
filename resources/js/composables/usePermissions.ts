import { usePage } from '@inertiajs/vue3';

export function usePermissions() {
    const page = usePage<AppPageProps>();
    const user = page.props.auth.user;

    const hasRole = (name: string) => user?.roles.includes(name);
    const hasPermission = (name: string) => user?.permissions.includes(name);

    return { hasRole, hasPermission };
}

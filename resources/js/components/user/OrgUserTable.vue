<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import { ShieldAlert } from 'lucide-vue-next';
import { router, usePage, Link } from '@inertiajs/vue3';
import userRoutes from '@/routes/users/index';
import { promote } from '@/actions/App/Http/Controllers/UserController';

import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { computed } from 'vue';
import { formatRoleName } from '@/lib/utils';

const props = defineProps<{
    users: User[];
    showAdminToggle?: boolean;
    allRoles?: string[];
}>();

const page = usePage<AppPageProps>();
const allRoles = computed(() => props.allRoles ?? []);
const viewerIsSuperAdmin = computed(() => page.props.auth.user.is_super === true);
const viewerIsOrgAdmin = computed(() => page.props.auth.user.roles?.includes('org-admin'));
const canAssignRoles = computed(() => props.showAdminToggle && (viewerIsSuperAdmin.value || viewerIsOrgAdmin.value));

const NO_ROLE = '__none__';

const updateUserRole = (user: User, role: string) => {
    if (user.is_super) return;

    router.put(userRoutes.update(user.id).url, {
        organization_id: user.organization_id,
        role: role === NO_ROLE ? null : role,
    }, {
        preserveScroll: true,
    });
};

</script>

<template>
    <div class="divide-y divide-gray-50 dark:divide-zinc-800/50">
        <div
            v-for="user in users"
            :key="user.row_key"
            class="grid items-center hover:bg-gray-50/30 dark:hover:bg-zinc-800/10 transition-colors"
            :class="viewerIsSuperAdmin ? 'grid-cols-[1fr_auto_200px]' : 'grid-cols-[1fr_200px]'"
        >
            <div class="p-4 pl-8 min-w-0">
                <div class="flex items-center gap-3">
                    <UserInfo :user="user" />
                    <div v-if="user.is_super" class="flex items-center gap-1.5 px-2 py-0.5 rounded bg-amber-50 text-amber-600 border border-amber-100 dark:bg-amber-500/10 dark:border-amber-500/20">
                        <ShieldAlert class="w-3 h-3" />
                        <span class="text-[9px] font-black uppercase tracking-tighter">Super</span>
                    </div>
                </div>
            </div>

            <div v-if="viewerIsSuperAdmin" class="px-4 flex justify-center">
                <Link
                    v-if="!user.is_super"
                    :href="promote(user.id).url"
                    method="post"
                    as="button"
                    :preserve-scroll="true"
                    class="inline-flex items-center justify-center h-9 px-3 text-[10px] font-black uppercase tracking-widest whitespace-nowrap rounded-md border border-amber-200 text-amber-700 hover:bg-amber-50 dark:border-amber-500/30 dark:text-amber-400 dark:hover:bg-amber-500/10 transition-colors"
                >
                    Make Super Admin
                </Link>
            </div>

            <div class="p-4 pr-6 flex justify-center">
                <span v-if="user.is_super" class="text-[10px] font-black uppercase tracking-widest text-amber-600 dark:text-amber-400">
                    Super Admin
                </span>
                <Select
                    v-else-if="canAssignRoles"
                    :model-value="user.roles[0] ?? NO_ROLE"
                    @update:model-value="(val) => updateUserRole(user, String(val))"
                >
                    <SelectTrigger class="h-8 text-xs w-40">
                        <SelectValue placeholder="No role" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="NO_ROLE">No role</SelectItem>
                        <SelectItem v-for="role in allRoles" :key="role" :value="role">
                            {{ formatRoleName(role) }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <span v-else class="text-xs text-gray-500 dark:text-zinc-400">
                    {{ user.roles[0] ? formatRoleName(user.roles[0]) : '—' }}
                </span>
            </div>
        </div>
    </div>
</template>

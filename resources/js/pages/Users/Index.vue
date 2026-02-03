<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import UserInfo from '@/components/UserInfo.vue';
import ResourceSearch from '@/components/ResourceSearch.vue';
import { useResourceExpansion } from '@/composables/useResourceExpansion';
import { Head, router } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { ref, computed } from 'vue';
import {
    Building2,
    ChevronDown,
    ChevronRight,
    CircleUserRound,
    ShieldAlert
} from 'lucide-vue-next';
import userRoutes from '@/routes/users/index';

const props = defineProps<{
    users: Record<string, User[]>;
    allRoles: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: userRoutes.index().url },
];

// --- SEARCH & FILTERING ---
// Transform the Record<string, User[]> into a searchable Array
const orgData = computed(() =>
    Object.entries(props.users).map(([name, members]) => ({
        id: name,
        name: name,
        members: members
    }))
);

const filteredOrgs = ref([...orgData.value]);

// --- RESOURCE EXPANSION ---
const {
    collapsedStates: collapsedOrgs,
    toggle: toggleOrg,
    handleSearchExpand
} = useResourceExpansion(orgData.value);

// --- METHODS ---
const toggleAdminStatus = (user: User) => {
    if (user.is_super) return;

    router.put(userRoutes.update(user.id).url, {
        organization_id: user.organization_id,
        is_admin: !user.roles.includes('org-admin')
    }, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="User Management" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-8 max-w-5xl mx-auto w-full">
            <div class="mb-8">
                <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white uppercase flex items-center gap-3">
                    <CircleUserRound class="w-8 h-8 text-indigo-500" />
                    User Management
                </h1>
                <p class="text-sm text-gray-500 mt-1">
                    Manage system roles and administrative privileges by organization.
                </p>
            </div>

            <div class="pb-6">
                <ResourceSearch
                    :items="orgData"
                    :search-keys="['name', 'members']"
                    placeholder="Search by organization, name, or email..."
                    @update:filtered="filteredOrgs = $event"
                    @update:expand="handleSearchExpand"
                />
            </div>

            <div class="space-y-6">
                <div v-if="filteredOrgs.length === 0" class="p-12 text-center border-2 border-dashed border-gray-200 dark:border-zinc-800 rounded-2xl">
                    <p class="text-gray-500 font-medium italic">No organizations or users found matching your search.</p>
                </div>

                <div v-for="org in filteredOrgs" :key="org.id"
                    class="bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-800 rounded-xl overflow-hidden shadow-sm"
                >
                    <button
                        @click="toggleOrg(org.id)"
                        class="w-full flex items-center justify-between p-4 bg-gray-50/50 dark:bg-zinc-800/50 border-b border-gray-200 dark:border-zinc-800 hover:bg-gray-100 dark:hover:bg-zinc-800 transition-colors"
                    >
                        <div class="flex items-center gap-3">
                            <component :is="collapsedOrgs[org.id] ? ChevronRight : ChevronDown" class="w-4 h-4 text-gray-400" />
                            <Building2 class="w-5 h-5 text-indigo-500" />
                            <h2 class="font-black uppercase tracking-tight text-sm text-gray-700 dark:text-zinc-200">
                                {{ org.name }}
                            </h2>
                            <span class="text-[10px] bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-400 px-2 py-0.5 rounded-full font-black">
                                {{ org.members.length }} {{ org.members.length === 1 ? 'User' : 'Users' }}
                            </span>
                        </div>
                    </button>

                    <div v-if="!collapsedOrgs[org.id]">
                        <div class="grid grid-cols-[1fr_128px] bg-gray-50/10 dark:bg-zinc-800/20 border-b border-gray-100 dark:border-zinc-800">
                            <div class="p-4 pl-8 text-[10px] font-black uppercase tracking-widest text-gray-400">User</div>
                            <div class="p-4 pr-12 text-[10px] font-black uppercase tracking-widest text-gray-400 text-center">Admin</div>
                        </div>

                        <div class="divide-y divide-gray-50 dark:divide-zinc-800/50">
                            <div v-for="user in org.members" :key="user.row_key"
                                class="grid grid-cols-[1fr_128px] items-center hover:bg-gray-50/30 dark:hover:bg-zinc-800/10 transition-colors"
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

                                <div class="p-4 pr-12 flex justify-center">
                                    <input
                                        type="checkbox"
                                        :checked="user.roles.includes('org-admin') || user.is_super"
                                        :disabled="user.is_super"
                                        @change="toggleAdminStatus(user)"
                                        class="h-4 w-4 shrink-0 rounded-sm border border-zinc-300 dark:border-zinc-700 text-indigo-600 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer transition-all shadow-sm"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import UserInfo from '@/components/UserInfo.vue';
import { Head, router } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { ref } from 'vue';
import {
    Building2,
    ChevronDown,
    ChevronRight,
    CircleUserRound,
    ShieldAlert
} from 'lucide-vue-next';
import userRoutes from '@/routes/users/index';

defineProps<{
    users: Record<string, User[]>;
    allRoles: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: userRoutes.index().url },
];

// --- STATE ---
const collapsedOrgs = ref<Record<string, boolean>>({});

const toggleOrg = (orgName: string) => {
    collapsedOrgs.value[orgName] = !collapsedOrgs.value[orgName];
};

// --- METHODS ---
const toggleAdminStatus = (user: User) => {
    if (user.is_super) return;

    // Wayfinder Implementation: Use .url from the route definition
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

            <div class="space-y-6">
                <div v-for="(orgUsers, orgName) in users" :key="orgName"
                    class="bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-800 rounded-xl overflow-hidden shadow-sm"
                >
                    <button
                        @click="toggleOrg(orgName)"
                        class="w-full flex items-center justify-between p-4 bg-gray-50/50 dark:bg-zinc-800/50 border-b border-gray-200 dark:border-zinc-800 hover:bg-gray-100 dark:hover:bg-zinc-800 transition-colors"
                    >
                        <div class="flex items-center gap-3">
                            <component :is="collapsedOrgs[orgName] ? ChevronRight : ChevronDown" class="w-4 h-4 text-gray-400" />
                            <Building2 class="w-5 h-5 text-indigo-500" />
                            <h2 class="font-black uppercase tracking-tight text-sm text-gray-700 dark:text-zinc-200">
                                {{ orgName }}
                            </h2>
                            <span class="text-[10px] bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-400 px-2 py-0.5 rounded-full font-black">
                                {{ orgUsers.length }}
                            </span>
                        </div>
                    </button>

                    <div v-if="!collapsedOrgs[orgName]">
                        <table class="w-full text-left border-collapse table-fixed">
                            <thead>
                                <tr class="bg-gray-50/10 dark:bg-zinc-800/20 border-b border-gray-100 dark:border-zinc-800">
                                    <th class="p-4 pl-8 text-[10px] font-black uppercase tracking-widest text-gray-400 w-full">User</th>
                                    <th class="p-4 text-[10px] font-black uppercase tracking-widest text-gray-400 w-32 text-center pr-12">Admin</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 dark:divide-zinc-800/50">
                                <tr v-for="user in orgUsers" :key="user.row_key" class="hover:bg-gray-50/30 dark:hover:bg-zinc-800/10 transition-colors">
                                    <td class="p-4 pl-8">
                                        <div class="flex items-center gap-3">
                                            <UserInfo :user="user" />
                                            <div v-if="user.is_super" class="flex items-center gap-1.5 px-2 py-0.5 rounded bg-amber-50 text-amber-600 border border-amber-100 dark:bg-amber-500/10 dark:border-amber-500/20">
                                                <ShieldAlert class="w-3 h-3" />
                                                <span class="text-[9px] font-black uppercase tracking-tighter">Super</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="p-4 pr-12">
                                        <div class="flex justify-center">
                                            <input
                                                type="checkbox"
                                                :checked="user.roles.includes('org-admin') || user.is_super"
                                                :disabled="user.is_super"
                                                @change="toggleAdminStatus(user)"
                                                class="h-4 w-4 shrink-0 rounded-sm border border-zinc-300 dark:border-zinc-700 text-indigo-600 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer transition-all shadow-sm"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

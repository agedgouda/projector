<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ResourceSearch from '@/components/ResourceSearch.vue';
import OrgUserTable from '@/components/user/OrgUserTable.vue'; // New home
import { useResourceExpansion } from '@/composables/useResourceExpansion';
import { Head } from '@inertiajs/vue3';
import { type BreadcrumbItem} from '@/types';
import { ref, computed } from 'vue';
import {
    Building2,
    ChevronDown,
    ChevronRight,
    CircleUserRound,
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

// Note: toggleAdminStatus is removed from here as it's now inside OrgUserTable
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

                    <div v-if="!collapsedOrgs[org.id]" class="p-4 border-t border-gray-100 dark:border-zinc-800">
                        <OrgUserTable
                            :users="org.members"
                            :show-admin-toggle="true"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

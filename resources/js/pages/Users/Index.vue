<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ResourceSearch from '@/components/ResourceSearch.vue';
import FlatRow from '@/components/FlatRow.vue';
import IconTile from '@/components/IconTile.vue';
import OrgUserTable from '@/components/user/OrgUserTable.vue';
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
        <div class="p-6 w-full">
            <div class="mb-8">
                <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white uppercase flex items-center gap-3">
                    <CircleUserRound class="w-8 h-8 text-projector-primary-500" />
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

            <div class="space-y-4">
                <div v-if="filteredOrgs.length === 0" class="p-12 text-center border-2 border-dashed border-gray-200 dark:border-zinc-800 rounded-2xl">
                    <p class="text-gray-500 font-medium italic">No organizations or users found matching your search.</p>
                </div>

                <div v-for="org in filteredOrgs" :key="org.id">
                    <FlatRow height="md" clickable @click="toggleOrg(org.id)">
                        <template #leading>
                            <component :is="collapsedOrgs[org.id] ? ChevronRight : ChevronDown" class="w-4 h-4 text-slate-400 shrink-0" />
                            <IconTile :icon="Building2" size="sm" />
                        </template>

                        <span class="font-bold text-[13px] text-slate-900 dark:text-slate-100 truncate">{{ org.name }}</span>

                        <template #trailing>
                            <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                                {{ org.members.length }} {{ org.members.length === 1 ? 'User' : 'Users' }}
                            </span>
                        </template>
                    </FlatRow>

                    <div v-if="!collapsedOrgs[org.id]" class="relative pl-7">
                        <div class="absolute left-[14px] top-0 bottom-0 w-px bg-slate-200 dark:bg-slate-800"></div>
                        <OrgUserTable
                            :users="org.members"
                            :show-admin-toggle="true"
                            :all-roles="allRoles"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup lang="ts">
import FlatRow from '@/components/FlatRow.vue';
import IconTile from '@/components/IconTile.vue';
import ResourceSearch from '@/components/ResourceSearch.vue';
import OrgUserTable from '@/components/user/OrgUserTable.vue';
import { useResourceExpansion } from '@/composables/useResourceExpansion';
import AppLayout from '@/layouts/AppLayout.vue';
import userRoutes from '@/routes/users/index';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import {
    Building2,
    ChevronDown,
    ChevronRight,
    CircleUserRound,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

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
        members: members,
    })),
);

const filteredOrgs = ref([...orgData.value]);

// --- RESOURCE EXPANSION ---
const {
    collapsedStates: collapsedOrgs,
    toggle: toggleOrg,
    handleSearchExpand,
} = useResourceExpansion(orgData.value);

// Note: toggleAdminStatus is removed from here as it's now inside OrgUserTable
</script>

<template>
    <Head title="User Management" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full p-6">
            <div class="mb-8">
                <h1
                    class="flex items-center gap-3 text-2xl font-black tracking-tight text-gray-900 uppercase dark:text-white"
                >
                    <CircleUserRound
                        class="h-8 w-8 text-projector-primary-500"
                    />
                    User Management
                </h1>
                <p class="mt-1 text-sm text-gray-500">
                    Manage system roles and administrative privileges by
                    organization.
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
                <div
                    v-if="filteredOrgs.length === 0"
                    class="rounded-2xl border-2 border-dashed border-gray-200 p-12 text-center dark:border-zinc-800"
                >
                    <p class="font-medium text-gray-500 italic">
                        No organizations or users found matching your search.
                    </p>
                </div>

                <div v-for="org in filteredOrgs" :key="org.id">
                    <FlatRow height="md" clickable @click="toggleOrg(org.id)">
                        <template #leading>
                            <component
                                :is="
                                    collapsedOrgs[org.id]
                                        ? ChevronRight
                                        : ChevronDown
                                "
                                class="h-4 w-4 shrink-0 text-slate-400"
                            />
                            <IconTile :icon="Building2" size="sm" />
                        </template>

                        <span
                            class="truncate text-[13px] font-bold text-slate-900 dark:text-slate-100"
                            >{{ org.name }}</span
                        >

                        <template #trailing>
                            <span
                                class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
                            >
                                {{ org.members.length }}
                                {{
                                    org.members.length === 1 ? 'User' : 'Users'
                                }}
                            </span>
                        </template>
                    </FlatRow>

                    <div v-if="!collapsedOrgs[org.id]" class="relative pl-7">
                        <div
                            class="absolute top-0 bottom-0 left-[14px] w-px bg-slate-200 dark:bg-slate-800"
                        ></div>
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

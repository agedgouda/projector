<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import UserInfo from '@/components/UserInfo.vue'; // Restored
import RoleManager from './Partials/RoleManager.vue';
import ClientAssignmentManager from './Partials/ClientAssignmentManager.vue';
import { Head } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { ref } from 'vue';
import { Shield, Users as UsersIcon } from 'lucide-vue-next';
import userRoutes from '@/routes/users/index';

// Unified Components
import ResourceHeader from '@/components/ResourceHeader.vue';
import ResourceList from '@/components/ResourceList.vue';

interface UserListItem extends User {
    roles: string[];
}

const props = defineProps<{
    users: UserListItem[];
    allRoles: string[];
    allClients: { id: string, company_name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: userRoutes.index().url },
];

// --- STATE ---
const collapsedUsers = ref<Record<number | string, boolean>>(
    Object.fromEntries(props.users.map(u => [u.id, true]))
);

const userViewMode = ref<Record<number | string, 'roles' | 'clients'>>(
    Object.fromEntries(props.users.map(u => [u.id, 'roles']))
);

// --- METHODS ---
const toggleUser = (id: number | string) => {
    collapsedUsers.value[id] = !collapsedUsers.value[id];
};

const setViewMode = (userId: number | string, mode: 'roles' | 'clients') => {
    userViewMode.value[userId] = mode;
};
</script>

<template>
    <Head title="User Management" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-5xl mx-auto w-full">
            <div class="mb-8">
                <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white uppercase">
                    User Management
                </h1>
                <p class="text-sm text-gray-500">
                    Manage system access, permissions, and client visibility.
                </p>
            </div>

            <ResourceList :items="users">
                <template #default="{ item: user }">
                    <div class="w-full">
                        <ResourceHeader
                            title=""
                            :collapsed="collapsedUsers[user.id]"
                            @toggle="toggleUser(user.id)"
                        >
                            <template #title>
                                <div class="flex items-center gap-3">
                                    <UserInfo :user="user" />
                                    <span v-if="user.roles.includes('admin')" class="px-2 py-0.5 rounded-md bg-amber-50 dark:bg-amber-500/10 text-amber-600 text-[9px] uppercase font-black border border-amber-100 dark:border-amber-500/20">
                                        Admin
                                    </span>
                                </div>
                            </template>
                        </ResourceHeader>

                        <div v-if="!collapsedUsers[user.id]" class="ml-10 mt-4 space-y-4">

                            <div class="flex items-center gap-4 border-b border-gray-100 dark:border-gray-800 pb-2">
                                <button
                                    @click="setViewMode(user.id, 'roles')"
                                    :class="[
                                        'flex items-center gap-2 text-[10px] font-black uppercase tracking-widest pb-1 border-b-2 transition-all',
                                        userViewMode[user.id] === 'roles'
                                            ? 'border-indigo-500 text-indigo-600'
                                            : 'border-transparent text-gray-400 hover:text-gray-600'
                                    ]"
                                >
                                    <Shield class="w-3 h-3" />
                                    Roles
                                </button>
                                <button
                                    @click="setViewMode(user.id, 'clients')"
                                    :class="[
                                        'flex items-center gap-2 text-[10px] font-black uppercase tracking-widest pb-1 border-b-2 transition-all',
                                        userViewMode[user.id] === 'clients'
                                            ? 'border-indigo-500 text-indigo-600'
                                            : 'border-transparent text-gray-400 hover:text-gray-600'
                                    ]"
                                >
                                    <UsersIcon class="w-3 h-3" />
                                    Clients
                                </button>
                            </div>

                            <div class="pt-2">
                                <div v-if="userViewMode[user.id] === 'roles'" class="max-w-xl">
                                    <RoleManager :user="user" :all-roles="allRoles" />
                                </div>

                                <div v-else class="max-w-xl">
                                    <div v-if="user.roles.includes('admin')" class="p-4 rounded-2xl bg-amber-50/50 border border-amber-100 dark:bg-amber-500/5 dark:border-amber-500/20">
                                        <p class="text-xs text-amber-700 dark:text-amber-400 font-bold leading-relaxed">
                                            ADMIN ACCESS: This user has global visibility. Manual assignment is not required.
                                        </p>
                                    </div>
                                    <ClientAssignmentManager v-else :user="user" :all-clients="allClients" />
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </ResourceList>
        </div>
    </AppLayout>
</template>

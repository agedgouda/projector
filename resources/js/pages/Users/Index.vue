<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import UserInfo from '@/components/UserInfo.vue';
import RoleManager from './Partials/RoleManager.vue';
import ClientAssignmentManager from './Partials/ClientAssignmentManager.vue';
import { Head } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { ref } from 'vue';
import { ChevronDown, Mail, Shield, Users as UsersIcon } from 'lucide-vue-next';
import userRoutes from '@/routes/users/index';

interface UserListItem extends User {
    roles: string[];
}

defineProps<{
    users: UserListItem[];
    allRoles: string[];
    allClients: { id: string, company_name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: userRoutes.index().url },
];

const expandedUserId = ref<number | null>(null);

const toggleUser = (id: number) => {
    expandedUserId.value = expandedUserId.value === id ? null : id;
};
</script>

<template>
    <Head title="User Management" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-none">
            <h2 class="text-2xl font-bold mb-6 text-foreground">User Management</h2>

            <div class="space-y-4 relative w-full">
                <div v-for="user in users" :key="user.id" class="w-full">

                    <div
                        @click="toggleUser(user.id)"
                        class="group relative flex items-center p-5 bg-white dark:bg-gray-900 border rounded-2xl transition-all duration-300 shadow-sm z-20 w-full cursor-pointer"
                        :class="expandedUserId === user.id
                            ? 'border-indigo-500 ring-4 ring-indigo-500/5'
                            : 'border-gray-100 dark:border-gray-800 hover:border-indigo-300 dark:hover:border-indigo-500/50'"
                    >
                        <div class="mr-4 transition-transform duration-300" :class="expandedUserId === user.id ? 'rotate-0' : '-rotate-90'">
                            <div class="flex items-center justify-center w-8 h-8 rounded-full bg-gray-50 dark:bg-gray-800 text-gray-400 group-hover:bg-indigo-50 group-hover:text-indigo-600 dark:group-hover:bg-indigo-500/10 transition-colors">
                                <ChevronDown class="w-5 h-5" />
                            </div>
                        </div>

                        <div class="flex items-center gap-4 flex-1">
                            <UserInfo :user="user" />

                            <div class="space-y-0.5">
                                <h3 class="font-bold text-gray-900 dark:text-white tracking-tight flex items-center">
                                    {{ user.name }}
                                    <span v-if="user.roles.includes('admin')" class="ml-2 px-2 py-0.5 rounded-md bg-amber-50 dark:bg-amber-500/10 text-amber-600 text-[10px] uppercase font-black border border-amber-100 dark:border-amber-500/20">
                                        Admin
                                    </span>
                                </h3>
                                <div class="flex items-center gap-1.5 text-sm text-gray-500 font-medium">
                                    <Mail class="w-3.5 h-3.5 opacity-60" />
                                    {{ user.email }}
                                </div>
                            </div>
                        </div>

                        <div class="hidden md:block">
                             <span class="text-[10px] font-black uppercase tracking-widest text-gray-400">
                                {{ user.roles.length }} {{ user.roles.length === 1 ? 'Role' : 'Roles' }}
                             </span>
                        </div>
                    </div>

                    <transition
                        enter-active-class="transition-all duration-500 ease-out"
                        leave-active-class="transition-all duration-300 ease-in"
                        enter-from-class="opacity-0 -translate-y-8 max-h-0"
                        enter-to-class="opacity-100 translate-y-0 max-h-[1000px]"
                        leave-from-class="opacity-100 translate-y-0 max-h-[1000px]"
                        leave-to-class="opacity-0 -translate-y-8 max-h-0"
                    >
                        <div v-if="expandedUserId === user.id" class="overflow-hidden w-full">
                            <div class="pt-10 pb-8 px-8 ml-12 border-x border-b border-indigo-100 dark:border-indigo-500/20 bg-gray-50/50 dark:bg-indigo-500/5 rounded-b-3xl -mt-6">
                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">

                                    <div class="space-y-4">
                                        <div class="flex items-center gap-2 mb-2">
                                            <Shield class="w-4 h-4 text-indigo-500" />
                                            <h4 class="text-[10px] font-black uppercase tracking-[0.2em] text-indigo-500">System Permissions</h4>
                                            <div class="h-px bg-indigo-100 dark:bg-indigo-500/20 flex-1"></div>
                                        </div>
                                        <RoleManager
                                            :user="user"
                                            :all-roles="allRoles"
                                        />
                                    </div>

                                    <div class="space-y-4">
                                        <div class="flex items-center gap-2 mb-2">
                                            <UsersIcon class="w-4 h-4 text-indigo-500" />
                                            <h4 class="text-[10px] font-black uppercase tracking-[0.2em] text-indigo-500">Client Access</h4>
                                            <div class="h-px bg-indigo-100 dark:bg-indigo-500/20 flex-1"></div>
                                        </div>

                                        <div v-if="user.roles.includes('admin')" class="p-4 rounded-2xl bg-amber-50/50 border border-amber-100 dark:bg-amber-500/5 dark:border-amber-500/20">
                                            <p class="text-xs text-amber-700 dark:text-amber-400 font-bold leading-relaxed">
                                                ADMIN ACCESS: This user has global visibility. Manual assignment is not required.
                                            </p>
                                        </div>

                                        <ClientAssignmentManager
                                            v-else
                                            :user="user"
                                            :all-clients="allClients"
                                        />
                                    </div>

                                </div>
                            </div>
                        </div>
                    </transition>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

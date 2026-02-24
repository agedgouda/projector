<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useForm, Head, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ShieldPlus, UserMinus, Trash2, User as UserIcon } from 'lucide-vue-next';
import ResourceHeader from '@/components/ResourceHeader.vue';
import ResourceCard from '@/components/ResourceCard.vue';
import ResourceList from '@/components/ResourceList.vue'; // Unified List component
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import roleRoutes from '@/routes/roles/index';
import { formatRoleName } from '@/lib/utils';

const props = defineProps<{
    roles: any[];
}>();

const page = usePage<AppPageProps>();
const authUser = page.props.auth.user;

// Default to collapsed (matching project logic)
const collapsedRoles = ref<Record<number, boolean>>(
    Object.fromEntries(props.roles.map(role => [role.id, true]))
);

const toggleRole = (roleId: number) => {
    collapsedRoles.value[roleId] = !collapsedRoles.value[roleId];
};

const form = useForm({ name: '' });
const submit = () => {
    form.post(roleRoutes.store().url, { onSuccess: () => form.reset() });
};

// --- Modal State ---
const isDeleteModalOpen = ref(false);
const modalLoading = ref(false);
const modalConfig = ref({ title: '', description: '', action: () => {} });

const deleteRole = (id: number) => {
    modalConfig.value = {
        title: 'Delete Role',
        description: 'Are you sure you want to delete this role entirely? This action cannot be undone.',
        action: () => {
            modalLoading.value = true;
            router.delete(roleRoutes.destroy(id).url, {
                onFinish: () => {
                    isDeleteModalOpen.value = false;
                    modalLoading.value = false;
                }
            });
        }
    };
    isDeleteModalOpen.value = true;
};

const unassignUser = (roleId: number, userId: number) => {
    modalConfig.value = {
        title: 'Unassign User',
        description: 'Are you sure you want to remove this user from this role?',
        action: () => {
            modalLoading.value = true;
            const targetUrl = roleRoutes.users.destroy({
                role: roleId,
                user: userId
            }).url;

            router.delete(targetUrl, {
                preserveScroll: true,
                onFinish: () => {
                    isDeleteModalOpen.value = false;
                    modalLoading.value = false;
                }
            });
        }
    };
    isDeleteModalOpen.value = true;
};

const breadcrumbs = [{ title: 'Roles', href: '#' }];
</script>

<template>
    <Head title="Manage Roles" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-4xl">
            <h2 class="text-2xl font-bold mb-6">System Roles</h2>

            <form @submit.prevent="submit" class="flex gap-4 mb-12 items-end">
                <div class="grid gap-2 w-full max-w-sm">
                    <label class="text-sm font-bold text-gray-500 uppercase tracking-wider">New Role Name</label>
                    <Input v-model="form.name" class="bg-white dark:bg-gray-800" placeholder="e.g. support" />
                </div>
                <Button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold h-10">
                    <ShieldPlus class="w-4 h-4 mr-2" /> Add Role
                </Button>
            </form>

            <ResourceList :items="roles">
                <template #default="{ item: role }">
                    <div class="flex items-center gap-2 group">
                        <ResourceHeader
                            :title="formatRoleName(role.name)"
                            :count="role.users_count"
                            :collapsed="collapsedRoles[role.id]"
                            @toggle="toggleRole(role.id)"
                            class="flex-1"
                        />

                        <button
                            v-if="role.name !== 'admin'"
                            type="button"
                            @click="deleteRole(role.id)"
                            class="mt-4 opacity-0 group-hover:opacity-100 p-2 text-gray-400 hover:text-red-500 transition-all"
                        >
                            <Trash2 class="w-4 h-4" />
                        </button>
                    </div>

                    <div v-if="!collapsedRoles[role.id]" class="space-y-2 ml-10 mt-2">
                       <ResourceCard
                            v-for="user in role.users"
                            :key="user.id"
                            :title="user.name"
                            :description="user.email"
                            :show-delete="true"
                            @delete="unassignUser(role.id, user.id)"
                        >
                            <template #icon>
                                <div class="p-1.5 rounded-lg bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600">
                                    <UserIcon class="w-4 h-4" />
                                </div>
                            </template>

                            <template #actions>
                                <div v-if="role.name === 'admin' && user.id === authUser.id" class="text-[10px] font-black uppercase tracking-widest text-indigo-400 italic pr-3">
                                    Current User
                                </div>
                                <div v-else class="flex items-center gap-1 text-[10px] font-black uppercase tracking-widest text-gray-400 group-hover:text-red-500 transition-colors">
                                    <UserMinus class="w-3.5 h-3.5" />
                                    <span>Unassign</span>
                                </div>
                            </template>
                        </ResourceCard>
                    </div>
                </template>
            </ResourceList>
        </div>

        <ConfirmDeleteModal
            :open="isDeleteModalOpen"
            :title="modalConfig.title"
            :description="modalConfig.description"
            :loading="modalLoading"
            @close="isDeleteModalOpen = false"
            @confirm="modalConfig.action"
        />
    </AppLayout>
</template>

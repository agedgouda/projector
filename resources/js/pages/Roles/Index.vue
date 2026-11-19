<script setup lang="ts">
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ResourceCard from '@/components/ResourceCard.vue';
import ResourceHeader from '@/components/ResourceHeader.vue';
import ResourceList from '@/components/ResourceList.vue'; // Unified List component
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatRoleName } from '@/lib/utils';
import roleRoutes from '@/routes/roles/index';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ShieldPlus,
    Trash2,
    User as UserIcon,
    UserMinus,
} from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    roles: any[];
}>();

const page = usePage<AppPageProps>();
const authUser = page.props.auth.user;

// Default to collapsed (matching project logic)
const collapsedRoles = ref<Record<number, boolean>>(
    Object.fromEntries(props.roles.map((role) => [role.id, true])),
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
        description:
            'Are you sure you want to delete this role entirely? This action cannot be undone.',
        action: () => {
            modalLoading.value = true;
            router.delete(roleRoutes.destroy(id).url, {
                onFinish: () => {
                    isDeleteModalOpen.value = false;
                    modalLoading.value = false;
                },
            });
        },
    };
    isDeleteModalOpen.value = true;
};

const unassignUser = (roleId: number, userId: number) => {
    modalConfig.value = {
        title: 'Unassign User',
        description:
            'Are you sure you want to remove this user from this role?',
        action: () => {
            modalLoading.value = true;
            const targetUrl = roleRoutes.users.destroy({
                role: roleId,
                user: userId,
            }).url;

            router.delete(targetUrl, {
                preserveScroll: true,
                onFinish: () => {
                    isDeleteModalOpen.value = false;
                    modalLoading.value = false;
                },
            });
        },
    };
    isDeleteModalOpen.value = true;
};

const breadcrumbs = [{ title: 'Roles', href: roleRoutes.index().url }];
</script>

<template>
    <Head title="Manage Roles" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full p-6">
            <h2 class="mb-6 text-2xl font-bold">Organization Roles</h2>

            <form @submit.prevent="submit" class="mb-12 flex items-end gap-4">
                <div class="grid w-full max-w-sm gap-2">
                    <label
                        class="text-sm font-bold tracking-wider text-gray-500 uppercase"
                        >New Role Name</label
                    >
                    <Input
                        v-model="form.name"
                        class="bg-white dark:bg-gray-800"
                        placeholder="e.g. support"
                    />
                </div>
                <Button
                    type="submit"
                    class="h-10 bg-projector-primary-600 font-bold text-white hover:bg-projector-primary-700"
                >
                    <ShieldPlus class="mr-2 h-4 w-4" /> Add Role
                </Button>
            </form>

            <ResourceList :items="roles">
                <template #default="{ item: role }">
                    <div class="group flex items-center gap-2">
                        <ResourceHeader
                            :title="formatRoleName(role.name)"
                            :count="role.users_count"
                            :collapsed="collapsedRoles[role.id]"
                            @toggle="toggleRole(role.id)"
                            class="flex-1"
                        />

                        <button
                            v-if="role.name !== 'super-admin'"
                            type="button"
                            @click="deleteRole(role.id)"
                            class="mt-4 p-2 text-gray-400 transition-all hover:text-red-500"
                        >
                            <Trash2 class="h-4 w-4" />
                        </button>
                    </div>

                    <div v-if="!collapsedRoles[role.id]" class="mt-2 ml-10">
                        <ResourceCard
                            v-for="(user, userIndex) in role.users as any[]"
                            :key="user.id"
                            :row-index="userIndex"
                            :title="user.name"
                            :description="user.email"
                            :show-delete="true"
                            no-hover
                            always-show-actions
                            @delete="unassignUser(role.id, user.id)"
                        >
                            <template #icon>
                                <div
                                    class="rounded-lg bg-projector-primary-50 p-1.5 text-projector-primary-600 dark:bg-projector-primary-900/30"
                                >
                                    <UserIcon class="h-4 w-4" />
                                </div>
                            </template>

                            <template #actions>
                                <div
                                    v-if="
                                        role.name === 'super-admin' &&
                                        user.id === authUser.id
                                    "
                                    class="pr-3 text-[10px] font-black tracking-widest text-projector-primary-400 uppercase italic"
                                >
                                    Current User
                                </div>
                                <div
                                    v-else
                                    class="flex items-center gap-1 text-[10px] font-black tracking-widest text-gray-400 uppercase transition-colors group-hover:text-red-500"
                                >
                                    <UserMinus class="h-3.5 w-3.5" />
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

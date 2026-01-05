<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ClientEntryForm from './Partials/ClientEntryForm.vue';
import clientRoutes from '@/routes/clients/index';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { type BreadcrumbItem } from '@/types';
import {
    Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Plus, FolderOpen, Search, Pencil, Trash2, User as UserIcon } from 'lucide-vue-next';

// Unified Components
import ResourceHeader from '@/components/ResourceHeader.vue';
import ResourceList from '@/components/ResourceList.vue';
import ResourceCard from '@/components/ResourceCard.vue';
import ProjectIcon from '@/components/ProjectIcon.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';

const props = defineProps<{
    clients: any[];
    projectTypes: any[];
    activeClientId?: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Clients', href: clientRoutes.index.url() }];

// --- STATE ---
const collapsedClients = ref<Record<number | string, boolean>>(
    Object.fromEntries(props.clients.map(c => [c.id, true]))
);

const isFormOpen = ref(false);
const clientToEdit = ref<any | null>(null);

// --- DELETE MODAL STATE ---
const isDeleteModalOpen = ref(false);
const deleteConfig = ref({ id: 0, name: '', loading: false });

// --- METHODS ---
const toggleProjects = (id: string | number) => {
    collapsedClients.value[id] = !collapsedClients.value[id];
};

const openCreateModal = () => {
    clientToEdit.value = null;
    isFormOpen.value = true;
};

const handleEditRequest = (client: any) => {
    clientToEdit.value = client;
    isFormOpen.value = true;
};

const confirmDeleteClient = (client: any) => {
    deleteConfig.value = { id: client.id, name: client.company_name, loading: false };
    isDeleteModalOpen.value = true;
};

const executeDelete = () => {
    deleteConfig.value.loading = true;
    const routeUrl = clientRoutes.destroy(String(deleteConfig.value.id)).url;

    router.delete(routeUrl, {
        onFinish: () => {
            isDeleteModalOpen.value = false;
            deleteConfig.value.loading = false;
        }
    });
};

const handleFormSuccess = () => {
    isFormOpen.value = false;
    clientToEdit.value = null;
};

// --- STATE ---
// Track view mode per client: 'projects' or 'users'
const clientViewMode = ref<Record<number | string, 'projects' | 'users'>>(
    Object.fromEntries(props.clients.map(c => [c.id, 'projects']))
);

// Helper to switch modes
const setViewMode = (clientId: number | string, mode: 'projects' | 'users') => {
    clientViewMode.value[clientId] = mode;
};
</script>

<template>
    <Head title="Clients" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full max-w-5xl mx-auto">

            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white">Clients</h1>
                    <p class="text-sm text-gray-500">Manage your client relationships and project history.</p>
                </div>

                <Dialog v-model:open="isFormOpen">
                    <DialogTrigger asChild>
                        <Button @click="openCreateModal" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold h-11 px-6 rounded-xl shadow-lg shadow-indigo-500/20 active:scale-95 transition-all">
                            <Plus class="w-5 h-5 mr-2" /> Add New Client
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="sm:max-w-[500px]">
                        <DialogHeader>
                            <DialogTitle>{{ clientToEdit ? 'Edit Client' : 'New Client' }}</DialogTitle>
                            <DialogDescription>Update the details for {{ clientToEdit ? clientToEdit.company_name : 'the new client' }}.</DialogDescription>
                        </DialogHeader>
                        <ClientEntryForm :edit-data="clientToEdit" @clear-edit="handleFormSuccess" @success="handleFormSuccess" />
                    </DialogContent>
                </Dialog>
            </div>

            <ResourceList :items="clients">
                <template #default="{ item: client }">
                    <div class="flex items-center gap-2 group">
                        <ResourceHeader
                            :title="client.company_name"
                            :description="client.email"
                            :count="client.projects?.length || 0"
                            :collapsed="collapsedClients[client.id]"
                            @toggle="toggleProjects(client.id)"
                            class="flex-1"
                        />

                        <div class="flex items-center gap-1 mt-4 opacity-0 group-hover:opacity-100 transition-all">
                            <button @click="handleEditRequest(client)" class="p-2 text-gray-400 hover:text-indigo-600 transition-colors">
                                <Pencil class="w-4 h-4" />
                            </button>
                            <button @click="confirmDeleteClient(client)" class="p-2 text-gray-400 hover:text-red-500 transition-colors">
                                <Trash2 class="w-4 h-4" />
                            </button>
                        </div>
                    </div>

                    <div v-if="!collapsedClients[client.id]" class="ml-10 mt-4 space-y-4">

                        <div class="flex items-center gap-4 border-b border-gray-100 dark:border-gray-800 pb-2">
                            <button
                                @click="setViewMode(client.id, 'projects')"
                                :class="[
                                    'text-[10px] font-black uppercase tracking-widest pb-1 border-b-2 transition-all',
                                    clientViewMode[client.id] === 'projects'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-400 hover:text-gray-600'
                                ]"
                            >
                                Projects ({{ client.projects?.length || 0 }})
                            </button>
                            <button
                                @click="setViewMode(client.id, 'users')"
                                :class="[
                                    'text-[10px] font-black uppercase tracking-widest pb-1 border-b-2 transition-all',
                                    clientViewMode[client.id] === 'users'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-400 hover:text-gray-600'
                                ]"
                            >
                                Users ({{ client.users?.length || 0 }})
                            </button>
                        </div>

                        <div v-if="clientViewMode[client.id] === 'projects'" class="space-y-2">
                            <div v-if="client.projects?.length === 0" class="p-6 text-center text-gray-400 text-[10px] font-black uppercase tracking-widest border-2 border-dashed rounded-2xl">
                                No Projects
                            </div>
                            <ResourceCard
                                v-for="project in client.projects"
                                :key="`proj-${project.id}`"
                                :title="project.name"
                                :description="project.description"
                                :pillText="project.type?.name"
                                :show-delete="true"
                            >
                                <template #icon>
                                    <div class="p-1.5 rounded-lg bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600">
                                        <ProjectIcon v-if="project.type" :name="project.type.icon" size="16" />
                                        <FolderOpen v-else class="w-4 h-4" />
                                    </div>
                                </template>
                                <template #actions>
                                    <Link :href="`/projects/${project.id}`" class="mr-2">
                                        <Search class="w-3.5 h-3.5 text-indigo-600" />
                                    </Link>
                                </template>
                            </ResourceCard>
                        </div>

                        <div v-else class="space-y-2">
                            <div v-if="client.users?.length === 0" class="p-6 text-center text-gray-400 text-[10px] font-black uppercase tracking-widest border-2 border-dashed rounded-2xl">
                                No Users Assigned
                            </div>
                            <ResourceCard
                                v-for="user in client.users"
                                :key="`user-${user.id}`"
                                :title="user.name"
                                :description="user.email"
                                :show-delete="false"
                            >
                                <template #icon>
                                    <div class="p-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600">
                                        <UserIcon class="w-4 h-4" />
                                    </div>
                                </template>
                            </ResourceCard>
                        </div>
                    </div>
                </template>
            </ResourceList>
        </div>

        <ConfirmDeleteModal
            :open="isDeleteModalOpen"
            :title="`Delete ${deleteConfig.name}`"
            description="Are you sure? This will delete the client and all associated project records permanently."
            :loading="deleteConfig.loading"
            @close="isDeleteModalOpen = false"
            @confirm="executeDelete"
        />
    </AppLayout>
</template>

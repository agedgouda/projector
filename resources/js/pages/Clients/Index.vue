<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ClientEntryForm from './Partials/ClientEntryForm.vue';
import { useResourceExpansion } from '@/composables/useResourceExpansion';
import clientRoutes from '@/routes/clients/index';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { type BreadcrumbItem } from '@/types';
import {
    Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import {
    Plus,
    Pencil,
    Trash2,
    Building2,
    ChevronDown,
    ChevronRight,
} from 'lucide-vue-next';

// Unified Components
import ProjectFolio from '@/components/ProjectFolio.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ResourceSearch from '@/components/ResourceSearch.vue';

const props = defineProps<{
    clients: any[];
    projectTypes: any[];
    activeClientId?: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Clients', href: clientRoutes.index.url() }];

// --- STATE ---
const filteredClients = ref([...props.clients]);
const isFormOpen = ref(false);
const clientToEdit = ref<any | null>(null);

// --- RESOURCE EXPANSION (Shared Logic) ---
const {
    collapsedStates: collapsedClients,
    toggle: toggleProjects,
    handleSearchExpand
} = useResourceExpansion(props.clients);

// --- DELETE MODAL STATE ---
const isDeleteModalOpen = ref(false);
const deleteConfig = ref({ id: 0, name: '', loading: false });

// --- METHODS ---
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
</script>
<template>
    <Head title="Clients" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-8 max-w-5xl mx-auto w-full">

            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white uppercase flex items-center gap-3">
                        <Building2 class="w-8 h-8 text-indigo-500" />
                        Client Management
                    </h1>
                    <p class="text-sm text-gray-500 mt-1">Manage client relationships and project history.</p>
                </div>

                <div class="flex items-center gap-3 w-full md:w-auto">
                    <Dialog v-model:open="isFormOpen">
                        <DialogTrigger asChild>
                            <Button @click="openCreateModal" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold h-11 px-6 rounded-xl shadow-lg shadow-indigo-500/20 active:scale-95 transition-all">
                                <Plus class="w-5 h-5 mr-2" /> Add New Client
                            </Button>
                        </DialogTrigger>
                        <DialogContent class="sm:max-w-[500px]">
                            <DialogHeader>
                                <DialogTitle>{{ clientToEdit ? 'Edit Client' : 'New Client' }}</DialogTitle>
                                <DialogDescription>Update details for {{ clientToEdit ? clientToEdit.company_name : 'the new client' }}.</DialogDescription>
                            </DialogHeader>
                            <ClientEntryForm :edit-data="clientToEdit" @clear-edit="handleFormSuccess" @success="handleFormSuccess" />
                        </DialogContent>
                    </Dialog>
                </div>
            </div>

            <div class="pb-6">
                <ResourceSearch
                        :items="clients"
                        :search-keys="['company_name', 'projects']"
                        @update:filtered="filteredClients = $event"
                        @update:expand="handleSearchExpand"
                    />
            </div>

            <div class="space-y-4">
                <div v-if="filteredClients.length === 0" class="p-12 text-center border-2 border-dashed border-gray-200 dark:border-zinc-800 rounded-2xl">
                    <p class="text-gray-500 font-medium">No clients found matching your search.</p>
                </div>

                <div v-for="client in filteredClients" :key="client.id"
                    class="bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-800 rounded-xl overflow-hidden shadow-sm transition-all"
                >
                    <div class="w-full flex items-center justify-between p-4 bg-gray-50/50 dark:bg-zinc-800/50 transition-colors group">
                        <button
                            @click="toggleProjects(client.id)"
                            class="flex items-center gap-3 flex-1 text-left"
                        >
                            <component :is="collapsedClients[client.id] ? ChevronRight : ChevronDown" class="w-4 h-4 text-gray-400" />
                            <div class="flex flex-col">
                                <h2 class="font-black uppercase tracking-tight text-sm text-gray-700 dark:text-zinc-200 flex items-center gap-2">
                                    {{ client.company_name }}
                                    <span class="text-[10px] bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-400 px-2 py-0.5 rounded-full font-black">
                                        {{ client.projects?.length || 0 }} {{ (client.projects?.length === 1) ? 'Project' : 'Projects' }}
                                    </span>
                                </h2>
                                <span class="text-[11px] text-gray-400 font-medium">{{ client.contact_name }}</span>
                            </div>
                        </button>

                        <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-all">
                            <button @click="handleEditRequest(client)" class="p-2 text-gray-400 hover:text-indigo-600 transition-colors">
                                <Pencil class="w-4 h-4" />
                            </button>
                            <button @click="confirmDeleteClient(client)" class="p-2 text-gray-400 hover:text-red-500 transition-colors">
                                <Trash2 class="w-4 h-4" />
                            </button>
                        </div>
                    </div>

                    <div v-if="!collapsedClients[client.id]" class="border-t border-gray-100 dark:border-zinc-800">
                        <div class="divide-y divide-gray-50 dark:divide-zinc-800/50">
                            <div v-if="client.projects?.length === 0" class="p-8 text-center text-gray-400 text-[10px] font-black uppercase tracking-widest italic">
                                No active projects for this client
                            </div>
                            <div v-else v-for="project in client.projects" :key="`proj-${project.id}`" class="px-4">
                                <ProjectFolio :project="project" class="w-full" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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

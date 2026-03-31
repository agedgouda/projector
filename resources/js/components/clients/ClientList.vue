<script setup lang="ts">
import ClientEntryForm from '@/components/clients/ClientEntryForm.vue';
import ProjectEntryForm from '@/components/projects/ProjectEntryForm.vue';
import { useResourceExpansion } from '@/composables/useResourceExpansion';
import { usePermissions } from '@/composables/usePermissions';
import clientRoutes from '@/routes/clients/index';
import { router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import {
    Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Plus, Pencil, Trash2, ChevronDown, ChevronRight, FolderPlus } from 'lucide-vue-next';
import ProjectFolio from '@/components/projects/ProjectFolio.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ResourceSearch from '@/components/ResourceSearch.vue';

const props = defineProps<{
    clients: Client[];
    projectTypes: ProjectType[];
    redirectTo?: string;
}>();

const filteredClients = ref<Client[]>([...props.clients]);
const isFormOpen = ref(false);
const clientToEdit = ref<Client | null>(null);

const isProjectFormOpen = ref(false);
const targetClientForProject = ref<Client | null>(null);

const {
    collapsedStates: collapsedClients,
    toggle: toggleProjects,
    handleSearchExpand,
} = useResourceExpansion(props.clients);

const isDeleteModalOpen = ref(false);
const deleteConfig = ref({ id: '', name: '', loading: false });

const openCreateModal = () => {
    clientToEdit.value = null;
    isFormOpen.value = true;
};

const handleEditRequest = (client: Client) => {
    clientToEdit.value = client;
    isFormOpen.value = true;
};

const openAddProjectModal = (client: Client) => {
    targetClientForProject.value = client;
    isProjectFormOpen.value = true;
};

const handleProjectSuccess = () => {
    if (targetClientForProject.value) {
        collapsedClients.value[targetClientForProject.value.id] = false;
    }
    isProjectFormOpen.value = false;
    targetClientForProject.value = null;
};

const confirmDeleteClient = (client: Client) => {
    deleteConfig.value = { id: String(client.id), name: client.company_name, loading: false };
    isDeleteModalOpen.value = true;
};

const executeDelete = () => {
    deleteConfig.value.loading = true;
    router.delete(clientRoutes.destroy(deleteConfig.value.id).url, {
        onFinish: () => {
            isDeleteModalOpen.value = false;
            deleteConfig.value.loading = false;
        },
    });
};

const handleFormSuccess = () => {
    isFormOpen.value = false;
    clientToEdit.value = null;
};

const { hasRole } = usePermissions();
const canAddClient = computed(() => hasRole('super-admin') || hasRole('org-admin'));
</script>

<template>
    <div class="space-y-4">
        <div v-if="canAddClient" class="flex justify-end">
            <Dialog v-model:open="isFormOpen">
                <DialogTrigger as-child>
                    <Button
                        @click="openCreateModal"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold h-10 px-5 rounded-xl shadow-lg shadow-indigo-500/20 active:scale-95 transition-all"
                    >
                        <Plus class="w-4 h-4 mr-2" />
                        <span class="text-[10px] font-black uppercase tracking-widest">Add Client</span>
                    </Button>
                </DialogTrigger>
                <DialogContent class="sm:max-w-[500px]">
                    <DialogHeader>
                        <DialogTitle>{{ clientToEdit ? 'Edit Client' : 'New Client' }}</DialogTitle>
                        <DialogDescription>
                            {{ clientToEdit ? `Update details for ${clientToEdit.company_name}.` : 'Add a new client to this organization.' }}
                        </DialogDescription>
                    </DialogHeader>
                    <ClientEntryForm :edit-data="clientToEdit" @clear-edit="handleFormSuccess" @success="handleFormSuccess" />
                </DialogContent>
            </Dialog>
        </div>

        <ResourceSearch
            :items="clients"
            :search-keys="['company_name', 'projects']"
            @update:filtered="filteredClients = $event"
            @update:expand="handleSearchExpand"
        />

        <div class="space-y-4">
            <div v-if="filteredClients.length === 0" class="p-12 text-center border-2 border-dashed border-gray-200 dark:border-zinc-800 rounded-2xl">
                <p class="text-gray-500 font-medium">No clients found matching your search.</p>
            </div>

            <div
                v-for="client in filteredClients"
                :key="client.id"
                class="bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-800 rounded-xl overflow-hidden shadow-sm transition-all"
            >
                <div class="w-full flex items-center justify-between p-4 bg-gray-50/50 dark:bg-zinc-800/50 transition-colors group">
                    <button @click="toggleProjects(client.id)" class="flex items-center gap-3 flex-1 text-left">
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
                        <button @click="openAddProjectModal(client)" class="p-2 text-gray-400 hover:text-emerald-600 transition-colors" title="Add Project">
                            <FolderPlus class="w-4 h-4" />
                        </button>
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
                            <ProjectFolio :project="project" :redirect-to="redirectTo ?? '/clients'" class="w-full" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <Dialog v-model:open="isProjectFormOpen">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>New Project for {{ targetClientForProject?.company_name }}</DialogTitle>
                    <DialogDescription>Initialize a new project record for this client.</DialogDescription>
                </DialogHeader>
                <ProjectEntryForm
                    v-if="targetClientForProject"
                    :client="targetClientForProject"
                    :project-types="projectTypes"
                    @success="handleProjectSuccess"
                    @cancel="isProjectFormOpen = false"
                />
            </DialogContent>
        </Dialog>

        <ConfirmDeleteModal
            :open="isDeleteModalOpen"
            :title="`Delete ${deleteConfig.name}`"
            description="Are you sure? This will delete the client and all associated project records permanently."
            :loading="deleteConfig.loading"
            @close="isDeleteModalOpen = false"
            @confirm="executeDelete"
        />
    </div>
</template>

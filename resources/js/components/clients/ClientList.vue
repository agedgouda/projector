<script setup lang="ts">
import ClientEntryForm from '@/components/clients/ClientEntryForm.vue';
import ProjectEntryForm from '@/components/projects/ProjectEntryForm.vue';
import { useResourceExpansion } from '@/composables/useResourceExpansion';
import { usePermissions } from '@/composables/usePermissions';
import clientRoutes from '@/routes/clients/index';
import { router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import {
    Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Plus, Pencil, Trash2, ChevronDown, ChevronRight, FolderPlus, Building2 } from 'lucide-vue-next';
import ProjectFolio from '@/components/projects/ProjectFolio.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ResourceSearch from '@/components/ResourceSearch.vue';
import UpgradeModal from '@/components/UpgradeModal.vue';
import FlatRow from '@/components/FlatRow.vue';
import IconTile from '@/components/IconTile.vue';
import { FLAT_ACTION_BUTTON } from '@/lib/flat-ui';
import type { AppPageProps } from '@/types';

const props = defineProps<{
    clients: Client[];
    projectTypes: ProjectType[];
    redirectTo?: string;
}>();

const filteredClients = ref<Client[]>([...props.clients]);
const isFormOpen = ref(false);
const clientToEdit = ref<Client | null>(null);

watch(() => props.clients, (updated) => {
    filteredClients.value = [...updated];
    if (clientToEdit.value) {
        const fresh = updated.find(c => c.id === clientToEdit.value!.id);
        if (fresh) { clientToEdit.value = fresh; }
    }
});

const isProjectFormOpen = ref(false);
const targetClientForProject = ref<Client | null>(null);

const {
    collapsedStates: collapsedClients,
    toggle: toggleProjects,
    handleSearchExpand,
} = useResourceExpansion(props.clients);

const isDeleteModalOpen = ref(false);
const deleteConfig = ref({ id: '', name: '', loading: false });

const showUpgradeModal = ref(false);
const upgradeModalLimitKey = ref<'clients' | 'projects'>('clients');

const page = usePage<AppPageProps>();
const atLimit = computed(() => (page.props as any).orgMembership?.at_limit ?? {});

const openCreateModal = () => {
    if (atLimit.value.clients) {
        upgradeModalLimitKey.value = 'clients';
        showUpgradeModal.value = true;
        return;
    }
    clientToEdit.value = null;
    isFormOpen.value = true;
};

const handleEditRequest = (client: Client) => {
    clientToEdit.value = client;
    isFormOpen.value = true;
};

const openAddProjectModal = (client: Client) => {
    if (atLimit.value.projects) {
        upgradeModalLimitKey.value = 'projects';
        showUpgradeModal.value = true;
        return;
    }
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
            <Button
                @click="openCreateModal"
                class="font-bold h-10 px-5"
            >
                <Plus class="w-4 h-4 mr-2" />
                <span class="text-[10px] font-black uppercase tracking-widest">Add Client</span>
            </Button>

            <Dialog v-model:open="isFormOpen">
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

            <div v-for="client in filteredClients" :key="client.id">
                <FlatRow height="md" clickable @click="toggleProjects(client.id)">
                    <template #leading>
                        <component :is="collapsedClients[client.id] ? ChevronRight : ChevronDown" class="w-4 h-4 text-slate-400 shrink-0" />
                        <IconTile :src="client.logo_url" :alt="client.company_name" :icon="Building2" size="sm" />
                    </template>

                    <div class="flex flex-col min-w-0">
                        <span class="font-bold text-[13px] text-slate-900 dark:text-slate-100 flex items-center gap-2 truncate">
                            {{ client.company_name }}
                            <span v-if="client.inactive" class="text-[9px] font-black uppercase tracking-widest text-slate-400 border border-slate-200 dark:border-slate-700 px-1.5 py-0.5 rounded shrink-0">
                                Inactive
                            </span>
                        </span>
                        <span class="text-[11px] text-slate-400 truncate">{{ client.contact_name }}</span>
                    </div>

                    <template #trailing>
                        <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                            {{ client.projects?.length || 0 }} {{ (client.projects?.length === 1) ? 'Project' : 'Projects' }}
                        </span>
                    </template>

                    <template #actions>
                        <button type="button" @click.stop="openAddProjectModal(client)" :class="FLAT_ACTION_BUTTON" title="Add Project">
                            <FolderPlus class="w-3.5 h-3.5" />
                        </button>
                        <button type="button" @click.stop="handleEditRequest(client)" :class="FLAT_ACTION_BUTTON" title="Edit client">
                            <Pencil class="w-3.5 h-3.5" />
                        </button>
                        <button
                            type="button"
                            @click.stop="confirmDeleteClient(client)"
                            class="h-7 w-7 flex items-center justify-center rounded-md text-slate-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 opacity-0 group-hover:opacity-100 transition-colors"
                            title="Delete client"
                        >
                            <Trash2 class="w-3.5 h-3.5" />
                        </button>
                    </template>
                </FlatRow>

                <div v-if="!collapsedClients[client.id]" class="relative pl-7">
                    <div class="absolute left-[14px] top-0 bottom-0 w-px bg-slate-200 dark:bg-slate-800"></div>
                    <div v-if="client.projects?.length === 0" class="py-4 text-slate-400 text-[10px] font-black uppercase tracking-widest italic">
                        No active projects for this client
                    </div>
                    <ProjectFolio
                        v-else
                        v-for="project in client.projects"
                        :key="`proj-${project.id}`"
                        :project="project"
                        :redirect-to="redirectTo ?? '/clients'"
                        class="w-full"
                    />
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

        <UpgradeModal
            :open="showUpgradeModal"
            :limit-key="upgradeModalLimitKey"
            @close="showUpgradeModal = false"
        />
    </div>
</template>

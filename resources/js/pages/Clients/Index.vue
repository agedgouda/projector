<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ClientEntryForm from './Partials/ClientEntryForm.vue';
import ClientTable from './Partials/ClientTable.vue';
import ClientProjects from './Partials/ClientProjects.vue';
import clientRoutes from '@/routes/clients/index';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { type BreadcrumbItem } from '@/types';
import {
    Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Plus } from 'lucide-vue-next';

// Received from the Laravel Controller via Inertia
const props = defineProps<{
    clients: any[];
    projects: any[];
    projectTypes: any[];
    activeClientId?: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Clients',
        href: clientRoutes.index.url(),
    },
];

// --- SELECTION & NAVIGATION STATE ---
// Track which client is "open" to show projects
const selectedClientId = ref<string | null>(props.activeClientId || null);

// Track which client is currently being edited in the form partial
const clientToEdit = ref<any | null>(null);

// Sync selection state with URL (e.g., when clicking browser back/forward)
watch(() => props.activeClientId, (newId) => {
    selectedClientId.value = newId || null;
}, { immediate: true });

// --- COMPUTED ---
// Find the active client object based on the ID in the URL
const activeClient = computed(() =>
    props.clients.find(c => c.id === selectedClientId.value)
);

// Source of truth for projects (passed down to ClientProjects partial)
const activeClientProjects = computed(() => props.projects);

// --- NAVIGATION METHODS ---
const toggleProjects = (id: string) => {
    if (selectedClientId.value === id) {
        // If clicking the already active client, close it
        router.get(clientRoutes.index.url(), {}, { preserveState: true });
    } else {
        // Navigate to the show route for that client
        router.get(clientRoutes.show.url(id), {}, {
            preserveState: true,
            preserveScroll: true,
        });
    }
};

const isFormOpen = ref(false);

const openCreateModal = () => {
    clientToEdit.value = null;
    isFormOpen.value = true;
};

const handleEditRequest = (client: any) => {
    clientToEdit.value = client;
    isFormOpen.value = true;
};

const handleFormSuccess = () => {
    isFormOpen.value = false;
    clientToEdit.value = null;
};
</script>

<template>
    <Head title="Clients" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full"> <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white">Clients</h1>
                    <p class="text-sm text-gray-500">Manage your client relationships and project history.</p>
                </div>

                <Dialog v-model:open="isFormOpen">
                    <DialogTrigger asChild>
                        <Button @click="openCreateModal" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold h-11 px-6 rounded-xl shadow-lg shadow-indigo-500/20 transition-all active:scale-95">
                            <Plus class="w-5 h-5 mr-2" />
                            Add New Client
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="sm:max-w-[500px]">
                        <DialogHeader>
                            <DialogTitle>{{ clientToEdit ? 'Edit Client' : 'New Client' }}</DialogTitle>
                            <DialogDescription>
                                {{ clientToEdit ? 'Update existing client information.' : 'Enter the details to create a new client profile.' }}
                            </DialogDescription>
                        </DialogHeader>

                        <ClientEntryForm
                            :edit-data="clientToEdit"
                            @clear-edit="handleFormSuccess"
                            @success="handleFormSuccess"
                        />
                    </DialogContent>
                </Dialog>
            </div>

            <div class="w-full bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm overflow-hidden">
                <ClientTable
                    :clients="clients"
                    :selected-client-id="selectedClientId"
                    @toggle-projects="toggleProjects"
                    @edit="handleEditRequest"
                />
            </div>

            <div v-if="activeClient" class="mt-12 border-t dark:border-gray-800 pt-8 animate-in fade-in slide-in-from-bottom-4 duration-500">
                <div class="flex items-center gap-4 mb-6">
                    <div class="h-8 w-1 bg-indigo-600 rounded-full"></div>
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white">
                        Projects for {{ activeClient.company_name }}
                    </h2>
                </div>

                 <ClientProjects
                    :key="activeClient.id"
                    :client="activeClient"
                    :projects="activeClientProjects"
                    :projectTypes="projectTypes"
                />
            </div>

        </div>
    </AppLayout>
</template>

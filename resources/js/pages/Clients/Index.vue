<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ClientEntryForm from './Partials/ClientEntryForm.vue';
import ClientTable from './Partials/ClientTable.vue';
import clientRoutes from '@/routes/clients/index';
import { Head } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { type BreadcrumbItem } from '@/types';
import {
    Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Plus } from 'lucide-vue-next';

// Received from the Laravel Controller via Inertia
const props = defineProps<{
    clients: any[];
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


// --- NAVIGATION METHODS ---
const toggleProjects = (id: string) => {
    selectedClientId.value = selectedClientId.value === id ? null : id;
    // No router call needed = 0ms latency
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

            <div class="w-full">
                <ClientTable
                    :clients="clients"
                    :projectTypes="projectTypes"
                    :selected-client-id="selectedClientId"
                    @toggle-projects="toggleProjects"
                    @edit="handleEditRequest"
                />
            </div>
        </div>
    </AppLayout>
</template>

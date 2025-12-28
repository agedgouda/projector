<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ClientEntryForm from './Partials/ClientEntryForm.vue';
import ClientTable from './Partials/ClientTable.vue';
import ClientProjects from './Partials/ClientProjects.vue';
import clientRoutes from '@/routes/clients/index';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { type BreadcrumbItem } from '@/types';

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

const handleEditRequest = (client: any) => {
    clientToEdit.value = client;
    // Smooth scroll back to the top so the user sees the form is populated
    window.scrollTo({ top: 0, behavior: 'smooth' });
};
</script><template>
    <Head title="Clients" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-7xl mx-auto w-full">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-start w-full">

                <ClientEntryForm
                    :edit-data="clientToEdit"
                    @clear-edit="clientToEdit = null"
                />

                <ClientTable
                    :clients="clients"
                    :selected-client-id="selectedClientId"
                    @toggle-projects="toggleProjects"
                    @edit="handleEditRequest"
                />

            </div>
            <div v-if="activeClient" class="mt-8 border-t dark:border-gray-700 pt-8 animate-in fade-in slide-in-from-bottom-4">
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

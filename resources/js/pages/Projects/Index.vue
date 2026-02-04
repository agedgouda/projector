<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import ProjectForm from '@/components/ProjectForm.vue';
import ProjectFolio from '@/components/projects/ProjectFolio.vue';
import ResourceHeader from '@/components/ResourceHeader.vue';
import ResourceList from '@/components/ResourceList.vue';
import projectRoutes from '@/routes/projects/index';
import { type BreadcrumbItem } from '@/types';
import { Search, X } from 'lucide-vue-next';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/components/ui/dialog"
import { Button } from '@/components/ui/button';
import { PlusIcon } from 'lucide-vue-next';

const props = defineProps<{
    projects: any[];
    clients: any[];
    projectTypes: any[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Projects', href: projectRoutes.index.url() },
];

// --- State Management ---
const searchQuery = ref('');
const statusFilter = ref('all');
const collapsedGroups = ref<Record<number | string, boolean>>(
    Object.fromEntries(props.clients.map(client => [client.id, true]))
);
const isProjectModalOpen = ref(false);

const handleSuccess = () => {
    isProjectModalOpen.value = false;
};

// --- The Master List Logic (Preserved) ---
const displayItems = computed(() => {
    let list = [...props.projects];

    if (searchQuery.value.trim()) {
        const query = searchQuery.value.toLowerCase();
        list = list.filter(p =>
            p.name.toLowerCase().includes(query) ||
            p.client?.company_name?.toLowerCase().includes(query)
        );
    }

    list.sort((a, b) => {
        const clientA = a.client?.company_name || '';
        const clientB = b.client?.company_name || '';
        const clientComparison = clientA.localeCompare(clientB);
        if (clientComparison !== 0) return clientComparison;
        return a.name.localeCompare(b.name);
    });

    const flattened: any[] = [];
    let lastClientId: any = null;

    list.forEach((project) => {
        if (project.client?.id !== lastClientId) {
            flattened.push({
                isHeader: true,
                domId: `header-${project.client?.id}`,
                clientId: project.client?.id,
                name: project.client?.company_name || 'Unassigned'
            });
            lastClientId = project.client?.id;
        }

        if (!collapsedGroups.value[project.client?.id]) {
            flattened.push({
                ...project,
                isHeader: false,
                domId: `project-${project.id}`
            });
        }
    });

    return flattened;
});

// --- Helper Functions ---
const getProjectCount = (clientId: any) => {
    return props.projects.filter(p => p.client?.id === clientId).length;
};

const toggleGroup = (clientId: any) => {
    collapsedGroups.value[clientId] = !collapsedGroups.value[clientId];
};

watch(searchQuery, (newVal) => {
    if (newVal.trim() !== '') {
        collapsedGroups.value = {};
    }
});
</script>

<template>
    <Head title="Projects" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full">

            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white">Project Portfolio</h1>
                    <p class="text-sm text-gray-500">Global overview of all active client engagements.</p>
                </div>

                <Dialog v-model:open="isProjectModalOpen">
                    <DialogTrigger asChild>
                        <Button class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold h-11 px-6 rounded-xl shadow-lg shadow-indigo-500/20 active:scale-95 transition-all">
                            <PlusIcon class="w-5 h-5 mr-2" />
                            New Project
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="sm:max-w-[500px]">
                        <DialogHeader>
                            <DialogTitle>Create Project</DialogTitle>
                            <DialogDescription>
                                Enter project details to initialize the workspace.
                            </DialogDescription>
                        </DialogHeader>
                        <ProjectForm
                            :clients="clients"
                            :projectTypes="projectTypes"
                            @success="handleSuccess"
                        />
                    </DialogContent>
                </Dialog>
            </div>

            <div class="flex flex-col lg:flex-row gap-4 mb-8">
                <div class="relative flex-1 group">
                    <Search class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 group-focus-within:text-indigo-500 transition-colors" />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search projects, clients..."
                        class="block w-full pl-11 pr-10 py-3 border border-gray-200 dark:border-gray-700 rounded-2xl bg-white dark:bg-gray-900 text-sm focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all shadow-sm"
                    />
                    <button v-if="searchQuery" @click="searchQuery = ''" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400">
                        <X class="w-4 h-4" />
                    </button>
                </div>

                <div class="flex bg-gray-100/50 dark:bg-gray-800/50 p-1.5 rounded-2xl border border-gray-200 dark:border-gray-700 w-fit">
                    <button
                        v-for="status in ['all', 'active', 'completed']"
                        :key="status"
                        @click="statusFilter = status"
                        :class="[
                            'px-6 py-2 text-xs font-black rounded-xl transition-all capitalize tracking-wider',
                            statusFilter === status
                                ? 'bg-white dark:bg-gray-700 text-indigo-600 dark:text-indigo-400 shadow-sm'
                                : 'text-gray-500'
                        ]"
                    >
                        {{ status }}
                    </button>
                </div>
            </div>

            <div class="relative w-full">
                <div v-if="displayItems.length === 0" class="text-center py-20 border-2 border-dashed rounded-3xl border-gray-100 dark:border-gray-800/50">
                    <p class="text-gray-400 font-medium">No projects found matching your criteria.</p>
                </div>

                <ResourceList :items="displayItems">
                    <template #default="{ item }">
                        <ResourceHeader
                            v-if="item.isHeader"
                            :title="item.name"
                            :count="getProjectCount(item.clientId)"
                            :collapsed="collapsedGroups[item.clientId]"
                            @toggle="toggleGroup(item.clientId)"
                        />

                        <div v-else class="w-full">
                            <ProjectFolio :project="item" class="w-full" />
                        </div>
                    </template>
                </ResourceList>
            </div>
        </div>
    </AppLayout>
</template>

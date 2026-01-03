<script setup lang="ts">
import { ref, computed, watch  } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import ProjectForm from '@/components/ProjectForm.vue';
import ProjectFolio from '@/components/ProjectFolio.vue';
import projectRoutes from '@/routes/projects/index';
import { type BreadcrumbItem } from '@/types';
import { Search, X, ChevronDown } from 'lucide-vue-next';
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
    documents: any[];
    projectTypes: any[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Projects', href: projectRoutes.index.url() },
];

// --- State Management ---
const searchQuery = ref('');
const statusFilter = ref('all');
const collapsedGroups = ref<Record<number | string, boolean>>({});
// State to control Dialog
const isProjectModalOpen = ref(false);
const handleSuccess = () => {
    isProjectModalOpen.value = false;
};

// --- The Master List Logic ---
// We flatten the data into a single array of "rows" (Headers and Projects)
// This allows TransitionGroup to animate every single element change.
const displayItems = computed(() => {
    let list = [...props.projects];

    // 1. Search Filter
    if (searchQuery.value.trim()) {
        const query = searchQuery.value.toLowerCase();
        list = list.filter(p =>
            p.name.toLowerCase().includes(query) ||
            p.client?.company_name?.toLowerCase().includes(query)
        );
    }

    // 2. Double Sort (Client Name then Project Name)
    list.sort((a, b) => {
        const clientA = a.client?.company_name || '';
        const clientB = b.client?.company_name || '';
        const clientComparison = clientA.localeCompare(clientB);
        if (clientComparison !== 0) return clientComparison;
        return a.name.localeCompare(b.name);
    });

    // 3. Build Flattened List
    const flattened: any[] = [];
    let lastClientId: any = null;

    list.forEach((project) => {
        if (project.client?.id !== lastClientId) {
            flattened.push({
                isHeader: true,
                domId: `header-${project.client?.id}`, // Changed 'id' to 'domId'
                clientId: project.client?.id,
                name: project.client?.company_name || 'Unassigned'
            });
            lastClientId = project.client?.id;
        }

        if (!collapsedGroups.value[project.client?.id]) {
            flattened.push({
                ...project, // This keeps the original project.id (e.g., 123)
                isHeader: false,
                domId: `project-${project.id}` // We add a separate domId for Vue's :key
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

// Auto-expand everything when searching
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
                    <p class="text-sm text-gray-500">Global overview of all active client engagements and document status.</p>
                </div>

                <Dialog v-model:open="isProjectModalOpen">
                    <DialogTrigger asChild>
                        <Button class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold h-11 px-6 rounded-xl shadow-lg shadow-indigo-500/20 transition-all active:scale-95">
                            <PlusIcon class="w-5 h-5 mr-2" />
                            New Project
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="sm:max-w-[500px] border-gray-200 dark:border-gray-800">
                        <DialogHeader>
                            <DialogTitle>Create Project</DialogTitle>
                            <DialogDescription>
                                Enter the project details below to initialize the document workspace.
                            </DialogDescription>
                        </DialogHeader>
                        <ProjectForm
                            :clients="clients"
                            :projectTypes="projectTypes"
                            :documents="documents"
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
                        placeholder="Search projects, clients, or descriptions..."
                        class="block w-full pl-11 pr-10 py-3 border border-gray-200 dark:border-gray-700 rounded-2xl bg-white dark:bg-gray-900 dark:text-gray-200 text-sm focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all shadow-sm"
                    />
                    <button v-if="searchQuery" @click="searchQuery = ''" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors">
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
                                : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
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

                <TransitionGroup name="list" tag="div" class="space-y-2 relative">
                    <div v-for="item in displayItems" :key="item.domId" class="w-full">

                        <div
                            v-if="item.isHeader"
                            @click="toggleGroup(item.clientId)"
                            class="flex items-center gap-4 mt-10 mb-6 cursor-pointer group/header select-none w-full"
                        >
                            <div class="flex items-center justify-center w-6 h-6 rounded-lg bg-gray-100 dark:bg-gray-800 group-hover/header:bg-indigo-500 group-hover/header:text-white transition-all">
                                <ChevronDown
                                    class="w-3.5 h-3.5 transition-transform duration-300"
                                    :class="{ '-rotate-90': collapsedGroups[item.clientId] }"
                                />
                            </div>
                            <div class="flex items-center gap-3">
                                <h3 class="text-sm font-black uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400 group-hover/header:text-indigo-600 transition-colors">
                                    {{ item.name }}
                                </h3>
                                <span class="text-[10px] font-mono font-bold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-500/10 px-2 py-0.5 rounded-full border border-indigo-100 dark:border-indigo-500/20">
                                    {{ getProjectCount(item.clientId) }}
                                </span>
                            </div>
                            <div class="h-px bg-gradient-to-r from-gray-200 to-transparent dark:from-gray-700 flex-1"></div>
                        </div>

                        <div v-else class="w-full">
                            <ProjectFolio :project="item" class="w-full" />
                        </div>

                    </div>
                </TransitionGroup>
            </div>
        </div>
    </AppLayout>
</template>

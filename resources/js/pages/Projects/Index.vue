<script setup lang="ts">
import { ref, computed, watch  } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import ProjectForm from '@/components/ProjectForm.vue';
import ProjectFolio from '@/components/ProjectFolio.vue';
import projectRoutes from '@/routes/projects/index';
import { type BreadcrumbItem } from '@/types';
import { Search, X, ChevronDown } from 'lucide-vue-next';

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
        <div class="p-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

                <div class="space-y-6">
                    <div class="bg-white dark:bg-gray-800 p-6 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm h-fit">
                        <h3 class="font-bold text-lg text-gray-900 dark:text-white mb-4">New Project</h3>
                        <ProjectForm
                            :clients="clients"
                            :projectTypes="projectTypes"
                            :documents="documents"
                        />
                    </div>
                </div>

                <div class="lg:col-span-2 space-y-6">

                    <div class="flex flex-col md:flex-row gap-4">
                        <div class="relative flex-1 group">
                            <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 group-focus-within:text-indigo-500 transition-colors" />
                            <input
                                v-model="searchQuery"
                                type="text"
                                placeholder="Search projects or clients..."
                                class="block w-full pl-10 pr-10 py-2.5 border border-gray-200 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-900 dark:text-gray-200 text-sm focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all shadow-sm"
                            />
                            <button v-if="searchQuery" @click="searchQuery = ''" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <X class="w-4 h-4" />
                            </button>
                        </div>

                        <div class="flex bg-gray-100 dark:bg-gray-800 p-1 rounded-xl border border-gray-200 dark:border-gray-700">
                            <button
                                v-for="status in ['all', 'active', 'completed']"
                                :key="status"
                                @click="statusFilter = status"
                                :class="[
                                    'px-4 py-1.5 text-xs font-bold rounded-lg transition-all capitalize',
                                    statusFilter === status ? 'bg-white dark:bg-gray-700 text-indigo-600 dark:text-indigo-400 shadow-sm' : 'text-gray-500 hover:text-gray-700'
                                ]"
                            >
                                {{ status }}
                            </button>
                        </div>
                    </div>

                    <div class="relative">
                        <div v-if="displayItems.length === 0" class="text-center py-20 border-2 border-dashed rounded-2xl border-gray-100 dark:border-gray-800 text-gray-400">
                            No projects found matching your criteria.
                        </div>

                        <TransitionGroup name="list" tag="div" class="space-y-1">
                            <div v-for="item in displayItems" :key="item.id">

                                <div
                                    v-if="item.isHeader"
                                    @click="toggleGroup(item.clientId)"
                                    class="flex items-center gap-3 mt-8 mb-4 cursor-pointer group/header select-none"
                                >
                                    <div class="flex items-center justify-center w-5 h-5 rounded bg-gray-100 dark:bg-gray-800 group-hover/header:bg-indigo-50 transition-colors">
                                        <ChevronDown
                                            class="w-3 h-3 text-gray-500 transition-transform duration-300"
                                            :class="{ '-rotate-90': collapsedGroups[item.clientId] }"
                                        />
                                    </div>
                                    <div class="flex items-baseline gap-2">
                                        <h3 class="text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-400 group-hover/header:text-indigo-600 transition-colors">
                                            {{ item.name }}
                                        </h3>
                                        <span class="text-[10px] font-bold text-gray-400 bg-gray-50 dark:bg-gray-900 px-1.5 py-0.5 rounded border border-gray-100 dark:border-gray-800">
                                            {{ getProjectCount(item.clientId) }}
                                        </span>
                                    </div>
                                    <div class="h-px bg-gray-100 dark:bg-gray-800 flex-1"></div>
                                </div>

                                <div v-else class="mb-3">
                                    <ProjectFolio :project="item" />
                                </div>

                            </div>
                        </TransitionGroup>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Core List Transition */
.list-enter-active,
.list-leave-active {
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.list-enter-from {
    opacity: 0;
    transform: translateX(30px);
}

.list-leave-to {
    opacity: 0;
    transform: translateX(-30px);
}

/* Move transition handles the "sliding up" of items when one is deleted */
.list-move {
    transition: transform 0.4s ease;
}

/* Absolute position for leaving items ensures they don't "block" the sliding animation */
.list-leave-active {
    position: absolute;
    width: 100%;
    z-index: 0;
}
</style>

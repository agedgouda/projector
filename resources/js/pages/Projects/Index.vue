<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/layouts/AppLayout.vue';
import NewProjectModal from '@/components/projects/NewProjectModal.vue';
import ProjectFolio from '@/components/projects/ProjectFolio.vue';
import ResourceHeader from '@/components/ResourceHeader.vue';
import ResourceList from '@/components/ResourceList.vue';
import projectRoutes from '@/routes/projects/index';
import { type BreadcrumbItem } from '@/types';
import { Search, X } from 'lucide-vue-next';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    projects: Project[];
    clients: Client[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Projects', href: projectRoutes.index.url() },
];

const page = usePage<{ flash?: { success?: string; error?: string } }>();

onMounted(() => {
    const flash = page.props.flash;
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
});

watch(() => page.props.flash, (flash) => {
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
}, { deep: true });

// --- State Management ---
const searchQuery = ref('');
const collapsedGroups = ref<Record<number | string, boolean>>(
    Object.fromEntries(props.clients.map(client => [client.id, false]))
);
const handleSuccess = (clientId: string) => {
    collapsedGroups.value[clientId] = false;
};

// --- The Master List Logic (Preserved) ---
const displayItems = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();
    const matchesQuery = (project: Project) => !query ||
        project.name.toLowerCase().includes(query) ||
        project.client?.company_name?.toLowerCase().includes(query);

    // Built from the full, unfiltered set so parent/child relationships survive search —
    // otherwise a query matching only a sub-project would strand it with no visible parent.
    const childrenByParentId = new Map<string, Project[]>();
    props.projects.forEach((project) => {
        if (!project.parent_id) return;
        const siblings = childrenByParentId.get(project.parent_id) ?? [];
        siblings.push(project);
        childrenByParentId.set(project.parent_id, siblings);
    });

    const topLevel = props.projects
        .filter((project) => !project.parent_id)
        .map((project) => ({
            project,
            children: (childrenByParentId.get(project.id) ?? []).filter(matchesQuery),
        }))
        .filter(({ project, children }) => matchesQuery(project) || children.length > 0);

    topLevel.sort((a, b) => {
        const clientA = a.project.client?.company_name || '';
        const clientB = b.project.client?.company_name || '';
        const clientComparison = clientA.localeCompare(clientB);
        if (clientComparison !== 0) return clientComparison;
        return a.project.name.localeCompare(b.project.name);
    });
    topLevel.forEach(({ children }) => children.sort((a, b) => a.name.localeCompare(b.name)));

    const flattened: any[] = [];
    let lastClientId: any = null;

    topLevel.forEach(({ project, children }) => {
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
                isSubProject: false,
                domId: `project-${project.id}`
            });

            children.forEach((child) => {
                flattened.push({
                    ...child,
                    isHeader: false,
                    isSubProject: true,
                    domId: `project-${child.id}`
                });
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

                <NewProjectModal
                    :clients="clients"
                    :projects="projects"
                    @success="handleSuccess"
                />
            </div>

            <div class="flex flex-col lg:flex-row gap-4 mb-8">
                <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-white dark:bg-slate-900 p-2 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex-1">
                    <div class="relative w-full md:w-80 lg:w-96">
                        <Search class="absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
                        <Input
                            v-model="searchQuery"
                            placeholder="Search projects, clients..."
                            class="pl-11 pr-10 bg-slate-50 dark:bg-slate-950 border-none h-11 rounded-xl focus-visible:ring-1 focus-visible:ring-slate-300"
                        />
                        <button v-if="searchQuery" @click="searchQuery = ''" class="absolute right-3 top-1/2 -translate-y-1/2 p-0.5 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-full transition-colors">
                            <X class="w-3 h-3 text-slate-500" />
                        </button>
                    </div>
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
                            <ProjectFolio :project="item" :is-sub-project="item.isSubProject" :projects="projects" class="w-full" />
                        </div>
                    </template>
                </ResourceList>
            </div>
        </div>
    </AppLayout>
</template>

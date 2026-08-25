<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/layouts/AppLayout.vue';
import NewProjectModal from '@/components/projects/NewProjectModal.vue';
import ProjectFolioList from '@/components/projects/ProjectFolioList.vue';
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

// --- The Master List Logic ---
// Groups by client (search-filtered, parent kept alongside any matching child so a
// query matching only a sub-project doesn't strand it with no visible parent). Ordering
// and indentation of parent-vs-sub-projects within a client, plus their row striping,
// is ProjectFolioList's job — the same component the Clients tab uses, so both stay in
// sync automatically.
const clientGroups = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();
    const matchesQuery = (project: Project) => !query ||
        project.name.toLowerCase().includes(query) ||
        project.client?.company_name?.toLowerCase().includes(query);

    const childrenByParentId = new Map<string, Project[]>();
    props.projects.forEach((project) => {
        if (!project.parent_id) return;
        const siblings = childrenByParentId.get(project.parent_id) ?? [];
        siblings.push(project);
        childrenByParentId.set(project.parent_id, siblings);
    });

    const byClientId = new Map<string, { clientId: string; clientName: string; projects: Project[] }>();

    props.projects
        .filter((project) => !project.parent_id)
        .forEach((project) => {
            const children = (childrenByParentId.get(project.id) ?? []).filter(matchesQuery);
            if (!matchesQuery(project) && children.length === 0) return;

            const clientId = project.client?.id ?? 'unassigned';
            const group = byClientId.get(clientId) ?? {
                clientId,
                clientName: project.client?.company_name || 'Unassigned',
                projects: [],
            };
            group.projects.push(project, ...children);
            byClientId.set(clientId, group);
        });

    return [...byClientId.values()].sort((a, b) => a.clientName.localeCompare(b.clientName));
});

const toggleGroup = (clientId: string) => {
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
                <div v-if="clientGroups.length === 0" class="text-center py-20 border-2 border-dashed rounded-3xl border-gray-100 dark:border-gray-800/50">
                    <p class="text-gray-400 font-medium">No projects found matching your criteria.</p>
                </div>

                <ResourceList :items="clientGroups">
                    <template #default="{ item: group }">
                        <ResourceHeader
                            :title="group.clientName"
                            :count="group.projects.length"
                            :collapsed="collapsedGroups[group.clientId]"
                            @toggle="toggleGroup(group.clientId)"
                        />

                        <ProjectFolioList v-if="!collapsedGroups[group.clientId]" :projects="group.projects" />
                    </template>
                </ResourceList>
            </div>
        </div>
    </AppLayout>
</template>

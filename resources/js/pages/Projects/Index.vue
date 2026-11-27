<script setup lang="ts">
import NewProjectModal from '@/components/projects/NewProjectModal.vue';
import ProjectFolioList from '@/components/projects/ProjectFolioList.vue';
import ResourceHeader from '@/components/ResourceHeader.vue';
import ResourceList from '@/components/ResourceList.vue';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import projectRoutes from '@/routes/projects/index';
import { type BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { Search, X } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

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

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    },
    { deep: true },
);

// --- State Management ---
const searchQuery = ref('');
const collapsedGroups = ref<Record<number | string, boolean>>(
    Object.fromEntries(props.clients.map((client) => [client.id, false])),
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
    const matchesQuery = (project: Project) =>
        !query ||
        project.name.toLowerCase().includes(query) ||
        project.client?.company_name?.toLowerCase().includes(query);

    const childrenByParentId = new Map<string, Project[]>();
    props.projects.forEach((project) => {
        if (!project.parent_id) return;
        const siblings = childrenByParentId.get(project.parent_id) ?? [];
        siblings.push(project);
        childrenByParentId.set(project.parent_id, siblings);
    });

    const byClientId = new Map<
        string,
        { clientId: string; clientName: string; projects: Project[] }
    >();

    props.projects
        .filter((project) => !project.parent_id)
        .forEach((project) => {
            const children = (childrenByParentId.get(project.id) ?? []).filter(
                matchesQuery,
            );
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

    return [...byClientId.values()].sort((a, b) =>
        a.clientName.localeCompare(b.clientName),
    );
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
        <div class="w-full p-6">
            <div
                class="mb-8 flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center"
            >
                <div>
                    <h1
                        class="text-2xl font-black tracking-tight text-gray-900 dark:text-white"
                    >
                        Project Portfolio
                    </h1>
                    <p class="text-sm text-gray-500">
                        Global overview of all active client engagements.
                    </p>
                </div>

                <NewProjectModal :clients="clients" @success="handleSuccess" />
            </div>

            <div class="mb-8 flex flex-col gap-4 lg:flex-row">
                <div
                    class="flex flex-1 flex-col items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm md:flex-row dark:border-slate-800 dark:bg-slate-900"
                >
                    <div class="relative w-full md:w-80 lg:w-96">
                        <Search
                            class="absolute top-1/2 left-4 h-4 w-4 -translate-y-1/2 text-slate-400"
                        />
                        <Input
                            v-model="searchQuery"
                            placeholder="Search projects, clients..."
                            class="h-11 rounded-xl border-none bg-slate-50 pr-10 pl-11 focus-visible:ring-1 focus-visible:ring-slate-300 dark:bg-slate-950"
                        />
                        <button
                            v-if="searchQuery"
                            @click="searchQuery = ''"
                            class="absolute top-1/2 right-3 -translate-y-1/2 rounded-full p-0.5 transition-colors hover:bg-slate-200 dark:hover:bg-slate-700"
                        >
                            <X class="h-3 w-3 text-slate-500" />
                        </button>
                    </div>
                </div>
            </div>

            <div class="relative w-full">
                <div
                    v-if="clientGroups.length === 0"
                    class="rounded-3xl border-2 border-dashed border-gray-100 py-20 text-center dark:border-gray-800/50"
                >
                    <p class="font-medium text-gray-400">
                        No projects found matching your criteria.
                    </p>
                </div>

                <ResourceList :items="clientGroups">
                    <template #default="{ item: group }">
                        <ResourceHeader
                            :title="group.clientName"
                            :count="group.projects.length"
                            :collapsed="collapsedGroups[group.clientId]"
                            @toggle="toggleGroup(group.clientId)"
                        />

                        <ProjectFolioList
                            v-if="!collapsedGroups[group.clientId]"
                            :projects="group.projects"
                        />
                    </template>
                </ResourceList>
            </div>
        </div>
    </AppLayout>
</template>

<script setup lang="ts">
import ImportKindStep from '@/pages/Import/Partials/ImportKindStep.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import IconTile from '@/components/IconTile.vue';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';
import { type BreadcrumbItem } from '@/types';
import importWizardRoutes from '@/routes/import/index';
import { Head } from '@inertiajs/vue3';
import { ArrowLeft, Files, Search } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';

interface WizardProject {
    id: string;
    name: string;
    logo_url: string | null;
}

const props = defineProps<{
    projects: WizardProject[];
    googlePickerConfigured: boolean;
    googleApiKey: string | null;
    googleAppId: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Import', href: importWizardRoutes.index.url() },
];

// Kept in the URL (not just component state) so the round trip through Google's OAuth connect
// flow — which reloads this page fresh from the server, see ImportDocumentOptions.vue's own
// onMounted — lands back on the same project instead of an empty picker. Mirrors that same
// component's own query-param pattern for its `google_doc_import` flag.
const selectedProjectId = ref<string | null>(null);

onMounted(() => {
    selectedProjectId.value = new URLSearchParams(window.location.search).get('project');
});

const selectedProject = computed<WizardProject | null>(
    () => props.projects.find((p) => p.id === selectedProjectId.value) ?? null,
);

const setProjectInUrl = (id: string | null) => {
    const url = new URL(window.location.href);
    if (id) {
        url.searchParams.set('project', id);
    } else {
        url.searchParams.delete('project');
    }
    window.history.replaceState(window.history.state, '', url);
};

const selectProject = (project: WizardProject) => {
    selectedProjectId.value = project.id;
    setProjectInUrl(project.id);
};

const changeProject = () => {
    selectedProjectId.value = null;
    setProjectInUrl(null);
};

const search = ref('');
const filteredProjects = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.projects;
    return props.projects.filter((p) => p.name.toLowerCase().includes(q));
});
</script>

<template>
    <Head title="Import" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-8 p-6">
            <div>
                <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white">
                    Import
                </h1>
                <p class="mt-0.5 text-sm text-gray-500">
                    Bring a document, task list, event list, or meeting recording into one of
                    your projects.
                </p>
            </div>

            <!-- Step 1: choose a project -->
            <div v-if="!selectedProject" class="space-y-4">
                <Label class="mb-2 block text-[10px] font-black tracking-widest text-gray-400 uppercase">
                    1. Choose a Project
                </Label>

                <div class="relative max-w-sm">
                    <Search class="pointer-events-none absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
                    <Input v-model="search" placeholder="Search projects…" class="h-9 pl-9 text-[13px]" />
                </div>

                <div
                    v-if="projects.length === 0"
                    class="rounded-2xl border-2 border-dashed border-gray-100 py-16 text-center dark:border-gray-800/50"
                >
                    <p class="font-bold text-gray-500">No projects available to import into</p>
                    <p class="mt-1 text-sm text-gray-400">
                        You need to be an org admin or project lead on a project to import data into it.
                    </p>
                </div>

                <div v-else class="grid gap-0.5">
                    <button
                        v-for="project in filteredProjects"
                        :key="project.id"
                        type="button"
                        :class="['flex h-12 min-w-0 items-center gap-3 rounded-md px-2 text-left transition-colors', FLAT_ROW_HOVER]"
                        @click="selectProject(project)"
                    >
                        <IconTile :src="project.logo_url" :icon="Files" size="sm" />
                        <span class="truncate text-sm font-bold text-slate-900 dark:text-slate-100">
                            {{ project.name }}
                        </span>
                    </button>

                    <p v-if="filteredProjects.length === 0" class="px-2 py-6 text-sm text-gray-400">
                        No projects match "{{ search }}".
                    </p>
                </div>
            </div>

            <!-- Step 2+: choose what to import, then the existing panels/modals take over -->
            <div v-else class="space-y-4">
                <Button variant="ghost" size="sm" class="-ml-2 h-8 px-2 text-gray-500" @click="changeProject">
                    <ArrowLeft class="mr-1.5 h-3.5 w-3.5" />
                    <span class="text-[10px] font-black tracking-widest uppercase">Change Project</span>
                </Button>

                <div class="flex items-center gap-3">
                    <IconTile :src="selectedProject.logo_url" :icon="Files" size="md" />
                    <h2 class="text-lg font-black tracking-tight text-gray-900 dark:text-white">
                        {{ selectedProject.name }}
                    </h2>
                </div>

                <ImportKindStep
                    :key="selectedProject.id"
                    :project="selectedProject"
                    :google-picker-configured="googlePickerConfigured"
                    :google-api-key="googleApiKey"
                    :google-app-id="googleAppId"
                />
            </div>
        </div>
    </AppLayout>
</template>

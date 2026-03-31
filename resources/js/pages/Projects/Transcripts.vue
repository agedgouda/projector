<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { AlertCircle } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import AvailableRecordings from '@/pages/Projects/Partials/AvailableRecordings.vue';
import { meetingProviderLabel } from '@/lib/constants';
import projectRoutes from '@/routes/projects/index';

const props = defineProps<{
    project: Project;
    recordings: Recording[];
    importedIds: string[];
    crossProjectImportedIds: string[];
    providerError: string | null;
    provider: string | null;
    canManageTranscripts: boolean;
}>();

const breadcrumbs = computed(() => [
    { title: 'Projects', href: projectRoutes.index.url() },
    { title: props.project.name, href: projectRoutes.show.url(props.project.id) },
    { title: 'Meeting Transcripts', href: '' },
]);

const providerLabel = computed(() => meetingProviderLabel(props.provider));
</script>

<template>
    <Head :title="`Meeting Transcripts — ${project.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full space-y-10">

            <!-- Page header -->
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-black uppercase tracking-tighter text-gray-900 dark:text-white">
                        Meeting Transcripts
                    </h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ project.name }}
                        <template v-if="providerLabel"> · {{ providerLabel }}</template>
                    </p>
                </div>
            </div>

            <!-- No provider configured -->
            <div v-if="!provider" class="flex items-start gap-4 rounded-2xl border border-amber-200 bg-amber-50 dark:bg-amber-950/20 dark:border-amber-800 p-6">
                <AlertCircle class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
                <div>
                    <p class="font-bold text-sm text-amber-800 dark:text-amber-300">No meeting provider configured</p>
                    <p class="text-sm text-amber-700 dark:text-amber-400 mt-1">
                        An organization admin must configure a meeting provider (Zoom, Microsoft Teams, or Google Meet) in
                        <a href="/organizations" class="underline font-medium">Organization Settings</a>
                        before transcripts can be imported.
                    </p>
                </div>
            </div>

            <!-- Provider API error -->
            <div v-else-if="providerError" class="flex items-start gap-4 rounded-2xl border border-red-200 bg-red-50 dark:bg-red-950/20 dark:border-red-800 p-6">
                <AlertCircle class="w-5 h-5 text-red-500 shrink-0 mt-0.5" />
                <div>
                    <p class="font-bold text-sm text-red-800 dark:text-red-300">Failed to connect to {{ providerLabel }}</p>
                    <p class="text-sm text-red-700 dark:text-red-400 mt-1 font-mono break-all">{{ providerError }}</p>
                    <p class="text-sm text-red-600 dark:text-red-500 mt-2">
                        Check that your API credentials are correct in
                        <a href="/organizations" class="underline font-medium">Organization Settings</a>.
                    </p>
                </div>
            </div>

            <!-- Available recordings -->
            <AvailableRecordings
                v-else
                :project-id="project.id"
                :recordings="recordings"
                :imported-ids="importedIds"
                :cross-project-imported-ids="crossProjectImportedIds"
                :can-manage="canManageTranscripts"
            />

        </div>
    </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { meetingProviderLabel } from '@/lib/constants';
import AvailableRecordings from '@/pages/Projects/Partials/AvailableRecordings.vue';
import ImportDocumentOptions from '@/pages/Projects/Partials/ImportDocumentOptions.vue';
import projectRoutes from '@/routes/projects/index';
import { Head } from '@inertiajs/vue3';
import { AlertCircle } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    project: Project;
    recordings: Recording[];
    importedIds: string[];
    crossProjectImportedIds: string[];
    providerError: string | null;
    provider: string | null;
    canManageTranscripts: boolean;
    googlePickerConfigured: boolean;
    googleApiKey: string | null;
    googleAppId: string | null;
}>();

const breadcrumbs = computed(() => [
    { title: 'Projects', href: projectRoutes.index.url() },
    {
        title: props.project.name,
        href: projectRoutes.show.url(props.project.id),
    },
    { title: 'Meeting Transcripts', href: '' },
]);

const providerLabel = computed(() => meetingProviderLabel(props.provider));
</script>

<template>
    <Head :title="`Meeting Transcripts — ${project.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-10 p-6">
            <!-- Page header -->
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1
                        class="text-3xl font-black tracking-tighter text-gray-900 uppercase dark:text-white"
                    >
                        Meeting Transcripts
                    </h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ project.name }}
                        <template v-if="providerLabel">
                            · {{ providerLabel }}</template
                        >
                    </p>
                </div>
            </div>

            <!-- Import a document directly (Google Doc, Word, or text file) — independent of
                 whether a meeting provider is configured below. -->
            <ImportDocumentOptions
                :project-id="project.id"
                :can-manage="canManageTranscripts"
                :google-picker-configured="googlePickerConfigured"
                :google-api-key="googleApiKey"
                :google-app-id="googleAppId"
            />

            <!-- No provider configured -->
            <div
                v-if="!provider"
                class="flex items-start gap-4 rounded-2xl border border-amber-200 bg-amber-50 p-6 dark:border-amber-800 dark:bg-amber-950/20"
            >
                <AlertCircle class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <div>
                    <p
                        class="text-sm font-bold text-amber-800 dark:text-amber-300"
                    >
                        No meeting provider configured
                    </p>
                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                        An organization admin must configure a meeting provider
                        (Zoom, Microsoft Teams, or Google Meet) in
                        <a href="/organizations" class="font-medium underline"
                            >Organization Settings</a
                        >
                        before recordings can be automatically listed here.
                    </p>
                </div>
            </div>

            <!-- Provider API error -->
            <div
                v-else-if="providerError"
                class="flex items-start gap-4 rounded-2xl border border-red-200 bg-red-50 p-6 dark:border-red-800 dark:bg-red-950/20"
            >
                <AlertCircle class="mt-0.5 h-5 w-5 shrink-0 text-red-500" />
                <div>
                    <p class="text-sm font-bold text-red-800 dark:text-red-300">
                        Failed to connect to {{ providerLabel }}
                    </p>
                    <p
                        class="mt-1 font-mono text-sm break-all text-red-700 dark:text-red-400"
                    >
                        {{ providerError }}
                    </p>
                    <p class="mt-2 text-sm text-red-600 dark:text-red-500">
                        Check that your API credentials are correct in
                        <a href="/organizations" class="font-medium underline"
                            >Organization Settings</a
                        >.
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

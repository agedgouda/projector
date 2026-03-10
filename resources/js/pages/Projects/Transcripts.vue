<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch, type Ref } from 'vue';
import { toast } from 'vue-sonner';
import { AlertCircle, CheckCircle2, Download, FileText, Loader2, Video } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import AiProcessingHeader from '@/components/AiProcessingHeader.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useAiProcessing } from '@/composables/useAiProcessing';
import projectRoutes from '@/routes/projects/index';
import transcriptRoutes from '@/routes/projects/transcripts/index';

interface Recording {
    id: string;
    title: string;
    started_at: string;
    duration_minutes: number;
}

interface ImportedTranscript {
    id: string;
    name: string;
    processed_at: string | null;
    metadata: {
        recording_id?: string;
        provider?: string;
        meeting_date?: string;
    };
    created_at: string;
}

const props = defineProps<{
    project: Project;
    recordings: Recording[];
    imported: ImportedTranscript[];
    importedIds: string[];
    providerError: string | null;
    provider: string | null;
}>();

const breadcrumbs = computed(() => [
    { title: 'Projects', href: projectRoutes.index.url() },
    { title: props.project.name, href: projectRoutes.show.url(props.project.id) },
    { title: 'Meeting Transcripts', href: '' },
]);

const providerLabel = computed(() => {
    const labels: Record<string, string> = {
        zoom: 'Zoom',
        teams: 'Microsoft Teams',
        google_meet: 'Google Meet',
    };
    return props.provider ? (labels[props.provider] ?? props.provider) : null;
});

const pendingRecordings = computed(() =>
    props.recordings.filter(r => !props.importedIds.includes(r.id))
);

const formatDate = (iso: string) =>
    new Date(iso).toLocaleDateString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });

// ── AI processing state ───────────────────────────────────────────────────────

const importedDocs = ref([] as ExtendedDocument[]);

// Keep importedDocs in sync with the Inertia prop (initial load + after redirects).
watch(() => props.imported, (newImported) => {
    importedDocs.value = newImported as unknown as ExtendedDocument[];
}, { immediate: true, deep: true });

const targetBeingCreated = ref<string | null>(null);

const { aiStatusMessage, aiProgress, isAiProcessing } = useAiProcessing(
    props.project.id,
    importedDocs as unknown as Ref<ExtendedDocument[]>,
    targetBeingCreated,
    (updatedDoc) => {
        const idx = importedDocs.value.findIndex(d => d.id === updatedDoc.id);
        if (idx >= 0) {
            importedDocs.value[idx] = { ...importedDocs.value[idx], ...updatedDoc };
        } else {
            // Replace a matching optimistic placeholder rather than inserting a duplicate.
            const placeholderIdx = importedDocs.value.findIndex(
                d => d.id.startsWith('pending-') &&
                    (d as any).metadata?.recording_id === (updatedDoc as any).metadata?.recording_id
            );
            if (placeholderIdx >= 0) {
                importedDocs.value[placeholderIdx] = updatedDoc;
            } else {
                importedDocs.value.unshift(updatedDoc);
            }
        }
    },
    () => toast.success('Transcript imported', { description: 'The transcript is ready in your documents.' }),
    (message) => toast.error('Import failed', { description: message }),
);

// ── Import action ─────────────────────────────────────────────────────────────

const importing = ref<string | null>(null);

const importRecording = (recording: Recording) => {
    importing.value = recording.id;

    // Optimistically add a placeholder so the processing banner shows immediately.
    const placeholder = {
        id: `pending-${recording.id}`,
        name: recording.title,
        processed_at: null,
        metadata: { recording_id: recording.id },
        created_at: new Date().toISOString(),
    } as unknown as ExtendedDocument;
    importedDocs.value.unshift(placeholder);

    router.post(transcriptRoutes.store.url(props.project.id), {
        recording_id: recording.id,
        title: recording.title,
        started_at: recording.started_at,
    }, {
        preserveScroll: true,
        onError: (errors) => {
            // Remove the optimistic placeholder on failure.
            importedDocs.value = importedDocs.value.filter(d => d.id !== placeholder.id);
            toast.error('Import failed', { description: Object.values(errors)[0] as string });
        },
        onFinish: () => { importing.value = null; },
    });
};
</script>

<template>
    <Head :title="`Meeting Transcripts — ${project.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-8 max-w-5xl mx-auto w-full space-y-10">

            <AiProcessingHeader :is-processing="isAiProcessing" :progress="aiProgress" :message="aiStatusMessage" />

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

            <!-- Available recordings to import -->
            <template v-else>
                <section>
                    <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-4">
                        Available Recordings
                        <span class="ml-2 text-gray-300">(last 30 days)</span>
                    </h2>

                    <div v-if="pendingRecordings.length === 0" class="flex flex-col items-center justify-center py-16 rounded-2xl border border-dashed border-gray-200 bg-gray-50/50 dark:bg-gray-900/30 dark:border-gray-700">
                        <Video class="w-8 h-8 text-gray-300 mb-3" />
                        <p class="text-sm font-bold text-gray-500">No new recordings found</p>
                        <p class="text-xs text-gray-400 mt-1">All recent recordings have already been imported, or none exist yet.</p>
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="recording in pendingRecordings"
                            :key="recording.id"
                            class="flex items-center gap-4 p-4 rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 hover:border-indigo-200 dark:hover:border-indigo-700 transition-colors"
                        >
                            <div class="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950 shrink-0">
                                <Video class="w-4 h-4 text-indigo-500" />
                            </div>

                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-sm text-gray-900 dark:text-white truncate">{{ recording.title }}</p>
                                <p class="text-xs text-gray-400 mt-0.5">
                                    {{ formatDate(recording.started_at) }}
                                    <span v-if="recording.duration_minutes" class="ml-2">
                                        · {{ recording.duration_minutes }} min
                                    </span>
                                </p>
                            </div>

                            <Button
                                size="sm"
                                :disabled="importing === recording.id"
                                class="shrink-0 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl px-4 h-9 text-[10px] font-black uppercase tracking-widest"
                                @click="importRecording(recording)"
                            >
                                <Loader2 v-if="importing === recording.id" class="w-3 h-3 mr-1.5 animate-spin" />
                                <Download v-else class="w-3 h-3 mr-1.5" />
                                {{ importing === recording.id ? 'Importing...' : 'Import' }}
                            </Button>
                        </div>
                    </div>
                </section>
            </template>

            <!-- Already imported transcripts -->
            <section>
                <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-4">
                    Imported Transcripts
                </h2>

                <div v-if="imported.length === 0" class="flex flex-col items-center justify-center py-16 rounded-2xl border border-dashed border-gray-200 bg-gray-50/50 dark:bg-gray-900/30 dark:border-gray-700">
                    <FileText class="w-8 h-8 text-gray-300 mb-3" />
                    <p class="text-sm font-bold text-gray-500">No transcripts imported yet</p>
                    <p class="text-xs text-gray-400 mt-1">Imported transcripts will appear here as documents in your project.</p>
                </div>

                <div v-else class="space-y-3">
                    <div
                        v-for="doc in importedDocs"
                        :key="doc.id"
                        class="flex items-center gap-4 p-4 rounded-2xl border bg-white dark:bg-gray-900 transition-colors"
                        :class="doc.processed_at === null
                            ? 'border-indigo-200 dark:border-indigo-800'
                            : 'border-gray-200 dark:border-gray-700'"
                    >
                        <div class="p-2 rounded-xl shrink-0"
                            :class="doc.processed_at === null
                                ? 'bg-indigo-50 dark:bg-indigo-950'
                                : 'bg-green-50 dark:bg-green-950'"
                        >
                            <Loader2 v-if="doc.processed_at === null" class="w-4 h-4 text-indigo-500 animate-spin" />
                            <CheckCircle2 v-else class="w-4 h-4 text-green-500" />
                        </div>

                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-sm text-gray-900 dark:text-white truncate">{{ doc.name }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">
                                <template v-if="doc.processed_at === null">Importing…</template>
                                <template v-else>
                                    Imported {{ formatDate(doc.created_at) }}
                                    <template v-if="(doc as any).metadata?.meeting_date">
                                        · Meeting {{ formatDate((doc as any).metadata.meeting_date) }}
                                    </template>
                                </template>
                            </p>
                        </div>

                        <Badge
                            :variant="doc.processed_at === null ? 'outline' : 'secondary'"
                            class="text-[10px] font-black uppercase tracking-widest shrink-0"
                            :class="doc.processed_at === null ? 'text-indigo-500 border-indigo-200' : ''"
                        >
                            {{ doc.processed_at === null ? 'Processing' : 'Transcript' }}
                        </Badge>
                    </div>
                </div>
            </section>

        </div>
    </AppLayout>
</template>

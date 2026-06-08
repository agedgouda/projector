<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, router, Deferred } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Plus, FileText, CalendarDays, ChevronRight, Sparkles, RefreshCw, Eye } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import AvailableOrgRecordings from '@/components/AvailableOrgRecordings.vue';
import AiProgressBar from '@/components/AiProgressBar.vue';
import AiProcessingHeader from '@/components/AiProcessingHeader.vue';
import { type BreadcrumbItem } from '@/types';
import { globalAiState } from '@/state';
import IconTile from '@/components/IconTile.vue';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';
import statusMeetingsRoutes from '@/routes/status-meetings/index';
import orgDocumentsRoutes from '@/routes/organizations/documents/index';
import orgDocumentsDraftRoutes from '@/routes/organizations/documents/draft/index';
import projectDocumentsRoutes from '@/routes/projects/documents/index';

const props = defineProps<{
    currentOrg: { id: string; name: string };
    organizations: OrgOption[];
    statusMeetings: StatusMeeting[];
    canManage: boolean;
    meetingProvider: string | null;
    recordingsData?: RecordingsData;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Status Meetings', href: statusMeetingsRoutes.index.url() },
];

const activeTab = ref<'documentation' | 'recordings'>('documentation');
const expandedIds = ref<Set<string>>(new Set());
const processingIds = ref<Set<string>>(new Set());

onMounted(() => {
    const expandId = new URLSearchParams(window.location.search).get('expand');
    if (expandId) { expandedIds.value.add(expandId); }
});

const toggle = (id: string) => {
    if (expandedIds.value.has(id)) {
        expandedIds.value.delete(id);
    } else {
        expandedIds.value.add(id);
    }
};

const isExpanded = (id: string) => expandedIds.value.has(id);

const hasChildren = (meeting: StatusMeeting) =>
    meeting.ai_draft_groups.length > 0 || meeting.linked_documents.length > 0;

// ── AI processing state ───────────────────────────────────────────────────────

const isAnyProcessing = computed(() =>
    props.statusMeetings.some(m => m.ai_draft_status === 'processing')
);

const aiProgress = ref(0);
let progressTimer: ReturnType<typeof setInterval> | null = null;

watch(isAnyProcessing, (val) => {
    globalAiState.value.isProcessing = val;

    if (val) {
        aiProgress.value = 5;
        progressTimer = setInterval(() => {
            if (aiProgress.value < 80) aiProgress.value += 2;
        }, 800);
    } else {
        if (progressTimer) { clearInterval(progressTimer); progressTimer = null; }
        if (aiProgress.value > 0) {
            aiProgress.value = 100;
            setTimeout(() => { aiProgress.value = 0; }, 600);
        }
    }
}, { immediate: true });

onUnmounted(() => {
    if (progressTimer) clearInterval(progressTimer);
    globalAiState.value.isProcessing = false;
});

const aiStatusMessage = computed(() => {
    const names = props.statusMeetings
        .filter(m => m.ai_draft_status === 'processing')
        .map(m => m.name);
    return names.length ? `Extracting action items from "${names[0]}"…` : '';
});

// Poll while any meeting is processing
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const startPolling = () => {
    pollTimer = setTimeout(() => {
        router.reload({
            only: ['statusMeetings'],
            onSuccess: () => { if (isAnyProcessing.value) startPolling(); },
        });
    }, 4000);
};

watch(isAnyProcessing, (val) => {
    if (val) {
        startPolling();
    } else if (pollTimer) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
}, { immediate: true });

onUnmounted(() => { if (pollTimer) clearTimeout(pollTimer); });

// ── Actions ───────────────────────────────────────────────────────────────────

const triggerProcess = (meeting: StatusMeeting) => {
    processingIds.value.add(meeting.id);
    router.post(
        orgDocumentsRoutes.processDraft({ organization: props.currentOrg.id, orgDocument: meeting.id }).url,
        {},
        {
            preserveScroll: true,
            onError: () => toast.error('Failed to start extraction.'),
            onFinish: () => processingIds.value.delete(meeting.id),
        }
    );
};

const canProcess = (meeting: StatusMeeting) =>
    !!meeting.content &&
    meeting.ai_draft_status !== 'processing' &&
    !processingIds.value.has(meeting.id);

const switchOrg = (id: string) => {
    router.get(statusMeetingsRoutes.index.url({ query: { org: id } }));
};

const formatDate = (dateStr: string) =>
    new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });

const showUrl = (meeting: StatusMeeting) =>
    orgDocumentsRoutes.show({ organization: props.currentOrg.id, orgDocument: meeting.id }).url;

const docUrl = (doc: StatusMeetingLinkedDocument) =>
    projectDocumentsRoutes.show({ project: doc.project_id, document: doc.id }).url;
</script>

<template>
    <Head title="Status Meetings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 space-y-8 w-full">
            <AiProgressBar :is-processing="isAnyProcessing" :progress="aiProgress" />
            <AiProcessingHeader
                :is-processing="isAnyProcessing"
                :progress="aiProgress"
                :message="aiStatusMessage"
            />

            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white">Status Meetings</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Cross-project meeting notes and action items for {{ currentOrg.name }}.</p>
                </div>

                <div class="flex items-center gap-3">
                    <select
                        v-if="organizations.length > 1"
                        :value="currentOrg.id"
                        @change="switchOrg(($event.target as HTMLSelectElement).value)"
                        class="h-10 rounded-xl border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 text-sm font-medium text-slate-700 dark:text-zinc-300 focus:outline-none focus:ring-2 focus:ring-projector-primary-500"
                    >
                        <option v-for="org in organizations" :key="org.id" :value="org.id">{{ org.name }}</option>
                    </select>

                    <Button
                        v-if="canManage"
                        @click="router.visit(orgDocumentsRoutes.create({ organization: currentOrg.id }).url)"
                        class="font-bold h-10 px-5"
                    >
                        <Plus class="w-4 h-4 mr-2" />
                        <span class="text-[10px] font-black uppercase tracking-widest">New Status Meeting</span>
                    </Button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex items-center border-b border-gray-200 dark:border-gray-700">
                <button v-for="tab in ['documentation', 'recordings']" :key="tab"
                    @click="activeTab = tab as 'documentation' | 'recordings'"
                    :class="['px-8 py-4 text-[10px] font-black uppercase tracking-[0.2em] transition-all border-b-2 -mb-[1px]',
                        activeTab === tab ? 'border-projector-primary-500 text-projector-primary-600' : 'border-transparent text-gray-400 hover:text-gray-600']">
                    {{ tab === 'documentation' ? 'Documentation' : 'Recordings' }}
                </button>
            </div>

            <!-- Documentation Tab -->
            <div v-show="activeTab === 'documentation'">
                <div v-if="statusMeetings.length === 0" class="text-center py-20 border-2 border-dashed rounded-3xl border-gray-100 dark:border-gray-800/50 space-y-3">
                    <div class="flex justify-center">
                        <div class="p-4 bg-gray-100 dark:bg-zinc-800 rounded-full">
                            <CalendarDays class="w-8 h-8 text-gray-400" />
                        </div>
                    </div>
                    <p class="text-gray-500 font-bold">No status meetings yet</p>
                    <p class="text-sm text-gray-400">Create a status meeting to capture notes across multiple projects.</p>
                </div>

                <div v-else class="grid gap-3">
                    <div v-for="meeting in statusMeetings" :key="meeting.id" class="flex flex-col">

                        <!-- Meeting row -->
                        <div
                            :class="['flex items-center h-12 px-2 rounded-md transition-colors min-w-0 cursor-pointer mb-1', FLAT_ROW_HOVER]"
                            @click="router.visit(showUrl(meeting))"
                        >
                            <!-- Expand toggle -->
                            <div
                                v-if="hasChildren(meeting)"
                                class="w-6 h-6 flex items-center justify-center cursor-pointer hover:bg-slate-200/80 dark:hover:bg-slate-700/60 rounded-md mr-2 shrink-0"
                                @click.stop="toggle(meeting.id)"
                            >
                                <ChevronRight
                                    class="h-4 w-4 text-slate-400 transition-transform duration-300"
                                    :class="{ 'rotate-90': isExpanded(meeting.id) }"
                                />
                            </div>
                            <div v-else class="w-6 mr-2 shrink-0" />

                            <IconTile :icon="FileText" size="sm" />

                            <!-- Name + meta -->
                            <div class="flex-1 flex items-center gap-3 overflow-hidden mx-4 min-w-0">
                                <span class="font-bold truncate text-sm tracking-tight text-slate-900 dark:text-slate-100">
                                    {{ meeting.name }}
                                </span>

                                <!-- Processing indicator -->
                                <div v-if="meeting.ai_draft_status === 'processing'" class="flex items-center gap-1.5 shrink-0">
                                    <RefreshCw class="h-3 w-3 animate-spin text-projector-primary-500" />
                                    <span class="text-[10px] text-projector-primary-500 font-black uppercase tracking-widest animate-pulse">Processing…</span>
                                </div>

                                <!-- Pending review badge -->
                                <span
                                    v-else-if="meeting.ai_draft_status === 'pending_review'"
                                    class="text-[9px] font-black uppercase tracking-widest text-amber-600 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 px-2 py-0.5 rounded-full shrink-0"
                                >
                                    Pending Review
                                </span>

                                <span class="text-[9px] font-black tracking-widest uppercase text-slate-400 shrink-0">
                                    Status Meeting
                                </span>
                            </div>

                            <!-- Date + creator -->
                            <div class="hidden md:flex items-center gap-2 text-[11px] text-slate-400 shrink-0 mr-4">
                                <span v-if="meeting.creator">{{ meeting.creator.name }}</span>
                                <span v-if="meeting.creator" class="text-slate-300">·</span>
                                <span>{{ formatDate(meeting.created_at) }}</span>
                            </div>

                            <!-- Action buttons -->
                            <div class="flex items-center gap-2 shrink-0" @click.stop>
                                <!-- Process / Reprocess -->
                                <Button
                                    v-if="canManage && meeting.ai_draft_status !== 'processing' && meeting.ai_draft_status !== 'pending_review'"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="!canProcess(meeting)"
                                    @click="triggerProcess(meeting)"
                                    class="h-8 px-3 bg-violet-50 dark:bg-violet-950/30 text-violet-700 dark:text-violet-400 rounded-md"
                                >
                                    <Sparkles class="h-3.5 w-3.5 mr-1.5" />
                                    <span class="text-[10px] font-black uppercase tracking-wider">
                                        {{ meeting.ai_draft_status === 'committed' ? 'Reprocess' : 'Process' }}
                                    </span>
                                </Button>

                                <!-- Review (pending_review) -->
                                <Button
                                    v-if="meeting.ai_draft_status === 'pending_review'"
                                    variant="ghost"
                                    size="sm"
                                    @click="meeting.ai_draft_groups.length ? router.visit(orgDocumentsDraftRoutes.show({ organization: currentOrg.id, orgDocument: meeting.id, groupId: meeting.ai_draft_groups[0].group_id }).url) : router.visit(showUrl(meeting))"
                                    class="h-8 px-3 bg-amber-50 dark:bg-amber-950/30 text-amber-700 dark:text-amber-400 rounded-md"
                                >
                                    <Sparkles class="h-3.5 w-3.5 mr-1.5" />
                                    <span class="text-[10px] font-black uppercase tracking-wider">Review</span>
                                </Button>

                                <!-- View -->
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="router.visit(showUrl(meeting))"
                                    class="h-8 px-3 bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-400 rounded-md"
                                >
                                    <Eye class="h-3.5 w-3.5 mr-1.5" />
                                    <span class="text-[10px] font-black uppercase tracking-wider">View</span>
                                </Button>
                            </div>
                        </div>

                        <!-- Children (expanded) -->
                        <div v-if="isExpanded(meeting.id)" class="w-full">

                            <!-- Draft groups (pending_review) -->
                            <template v-if="meeting.ai_draft_status === 'pending_review'">
                                <div
                                    v-for="group in meeting.ai_draft_groups"
                                    :key="group.group_id"
                                    :class="['flex items-center gap-3 h-9 px-2 ml-8 rounded-md transition-colors cursor-pointer min-w-0 mb-1', FLAT_ROW_HOVER]"
                                    @click="router.visit(orgDocumentsDraftRoutes.show({ organization: currentOrg.id, orgDocument: meeting.id, groupId: group.group_id }).url)"
                                >
                                    <FileText class="h-3.5 w-3.5 text-amber-500 shrink-0" />
                                    <div class="flex-1 flex items-center gap-3 overflow-hidden min-w-0">
                                        <span class="text-sm font-semibold truncate text-slate-700 dark:text-slate-300">
                                            {{ group.document_title || group.project_name }}
                                        </span>
                                        <span class="text-[9px] font-black uppercase tracking-widest text-amber-500 bg-amber-50 dark:bg-amber-950/30 px-1.5 py-0.5 rounded-full shrink-0">
                                            Draft
                                        </span>
                                        <span v-if="group.client_name" class="text-[10px] text-slate-400 shrink-0">
                                            {{ group.client_name }}
                                        </span>
                                    </div>
                                </div>
                            </template>

                            <!-- Committed linked documents -->
                            <div
                                v-for="doc in meeting.linked_documents"
                                :key="doc.id"
                                :class="['flex items-center gap-3 h-9 px-2 ml-8 rounded-md transition-colors cursor-pointer min-w-0 mb-1', FLAT_ROW_HOVER]"
                                @click="router.visit(docUrl(doc))"
                            >
                                <FileText class="h-3.5 w-3.5 text-slate-400 shrink-0" />
                                <div class="flex-1 flex items-center gap-3 overflow-hidden min-w-0">
                                    <span class="text-sm font-semibold truncate text-slate-700 dark:text-slate-300">
                                        {{ doc.name }}
                                    </span>
                                    <span class="text-[9px] font-black uppercase tracking-widest text-slate-400 shrink-0">
                                        Action Items
                                    </span>
                                    <span v-if="doc.project_name" class="text-[10px] text-slate-400 shrink-0">
                                        {{ doc.project_name }}<template v-if="doc.client_name"> · {{ doc.client_name }}</template>
                                    </span>
                                </div>
                                <Eye class="h-3.5 w-3.5 text-slate-300 shrink-0" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recordings Tab -->
            <div v-show="activeTab === 'recordings'">
                <div v-if="!meetingProvider" class="flex flex-col items-center justify-center py-16 rounded-2xl border border-dashed border-gray-200 dark:border-gray-700">
                    <p class="text-sm font-bold text-gray-500">No meeting provider configured</p>
                    <p class="text-xs text-gray-400 mt-1">Configure a provider in Organization Settings to import recordings.</p>
                </div>

                <Deferred v-else data="recordingsData">
                    <template #fallback>
                        <div class="grid gap-0.5">
                            <div v-for="i in 4" :key="i" class="flex items-center gap-3 h-12 px-2 animate-pulse">
                                <div class="w-3.5 h-3.5 rounded bg-gray-100 dark:bg-gray-800 shrink-0" />
                                <div class="flex-1 flex items-center gap-3">
                                    <div class="h-3 bg-gray-100 dark:bg-gray-800 rounded w-40" />
                                    <div class="h-2.5 bg-gray-100 dark:bg-gray-800 rounded w-24" />
                                </div>
                                <div class="h-8 w-20 bg-gray-100 dark:bg-gray-800 rounded-md" />
                            </div>
                        </div>
                    </template>

                    <AvailableOrgRecordings
                        :organization-id="currentOrg.id"
                        :recordings="recordingsData.recordings"
                        :imported-ids="recordingsData.importedIds"
                        :can-manage="canManage"
                        :provider-error="recordingsData.providerError"
                    />
                </Deferred>
            </div>
        </div>
    </AppLayout>
</template>

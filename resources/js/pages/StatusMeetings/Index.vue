<script setup lang="ts">
import IconTile from '@/components/IconTile.vue';
import { Button } from '@/components/ui/button';
import {
    BANNER_PRIORITY,
    useGlobalProcessingBanner,
} from '@/composables/useGlobalProcessingBanner';
import { useProcessingReconciler } from '@/composables/useProcessingReconciler';
import AppLayout from '@/layouts/AppLayout.vue';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';
import { isProcessingMine } from '@/lib/isProcessingMine';
import { formatTimestampMdy } from '@/lib/utils';
import AvailableOrgRecordings from '@/pages/Organizations/Partials/AvailableOrgRecordings.vue';
import ImportDocumentOptions from '@/pages/Organizations/Partials/ImportDocumentOptions.vue';
import orgDocumentsDraftRoutes from '@/routes/organizations/documents/draft/index';
import orgDocumentsRoutes from '@/routes/organizations/documents/index';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import statusMeetingsRoutes from '@/routes/status-meetings/index';
import { globalAiState } from '@/state';
import { type BreadcrumbItem } from '@/types';
import { Deferred, Head, router, usePage } from '@inertiajs/vue3';
import {
    CalendarDays,
    ChevronRight,
    Eye,
    FileText,
    Plus,
    RefreshCw,
    Sparkles,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

const props = defineProps<{
    currentOrg: { id: string; name: string };
    statusMeetings: StatusMeeting[];
    canManage: boolean;
    meetingProvider: string | null;
    recordingsData?: RecordingsData;
    googlePickerConfigured: boolean;
    googleApiKey: string | null;
    googleAppId: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Status Meetings', href: statusMeetingsRoutes.index.url() },
];

const activeTab = ref<'documentation' | 'recordings'>('documentation');
const expandedIds = ref<Set<string>>(new Set());
const processingIds = ref<Set<string>>(new Set());

onMounted(() => {
    const expandId = new URLSearchParams(window.location.search).get('expand');
    if (expandId) {
        expandedIds.value.add(expandId);
    }
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

const currentUserId = usePage<AppPageProps>().props.auth.user.id;

// Gated to whoever triggered a given meeting's draft run (see isProcessingMine.ts) — otherwise
// any org member viewing this page would see the banner for a colleague's in-progress
// extraction, straight from this shared statusMeetings prop, regardless of who started it.
const isAnyProcessing = computed(() =>
    props.statusMeetings.some(
        (m) =>
            m.ai_draft_status === 'processing' &&
            isProcessingMine(
                {
                    processing_triggered_by_user_id:
                        m.processing_triggered_by_user_id,
                    creator_id: m.creator?.id,
                },
                currentUserId,
            ),
    ),
);

const aiProgress = ref(0);
let progressTimer: ReturnType<typeof setInterval> | null = null;

const { setBanner, clearBanner } = useGlobalProcessingBanner();

watch(
    isAnyProcessing,
    (val) => {
        globalAiState.value.isProcessing = val;

        if (val) {
            aiProgress.value = 5;
            progressTimer = setInterval(() => {
                if (aiProgress.value < 80) aiProgress.value += 2;
            }, 800);
        } else {
            if (progressTimer) {
                clearInterval(progressTimer);
                progressTimer = null;
            }
            if (aiProgress.value > 0) {
                aiProgress.value = 100;
                setTimeout(() => {
                    aiProgress.value = 0;
                }, 600);
            }
        }
    },
    { immediate: true },
);

onUnmounted(() => {
    if (progressTimer) clearInterval(progressTimer);
    globalAiState.value.isProcessing = false;
    clearBanner('ai-processing');
});

const aiStatusMessage = computed(() => {
    const names = props.statusMeetings
        .filter((m) => m.ai_draft_status === 'processing')
        .map((m) => m.name);
    return names.length ? `Extracting action items from "${names[0]}"…` : '';
});

watch(
    [isAnyProcessing, aiProgress, aiStatusMessage],
    ([processing, progress, message]) => {
        if (processing) {
            setBanner('ai-processing', {
                title: 'AI Sync Active',
                message,
                progress,
                priority: BANNER_PRIORITY.AI_PROCESSING,
            });
        } else {
            clearBanner('ai-processing');
        }
    },
    { immediate: true },
);

// Shared reconciliation poll (useProcessingReconciler.ts) — one org-wide poll instead of this
// page's own 4s self-rescheduling timer. Reloads statusMeetings once a meeting this page still
// shows as "processing" has actually dropped out of the server's processing_org_document_ids
// set (a status meeting's id doubles as its underlying OrgDocument's id — see
// OrgDocumentController::index()). Moves the reconciliation cadence from this page's old fixed
// 4s poll to the shared reconciler's own delayed/generous cadence (see
// useProcessingReconciler.ts); the on-screen progress bar above is a local fake ramp,
// unaffected by this change.
const {
    processingOrgDocumentIds,
    start: startReconciling,
    stop: stopReconciling,
} = useProcessingReconciler();

watch(
    isAnyProcessing,
    (val) => {
        if (val) startReconciling();
        else stopReconciling();
    },
    { immediate: true },
);

watch(processingOrgDocumentIds, (ids) => {
    const stillProcessing = props.statusMeetings.some(
        (m) => m.ai_draft_status === 'processing' && ids.has(m.id),
    );
    if (isAnyProcessing.value && !stillProcessing) {
        router.reload({ only: ['statusMeetings'] });
    }
});

onUnmounted(() => {
    if (isAnyProcessing.value) stopReconciling();
});

// ── Actions ───────────────────────────────────────────────────────────────────

const triggerProcess = (meeting: StatusMeeting) => {
    processingIds.value.add(meeting.id);
    router.post(
        orgDocumentsRoutes.processDraft({
            organization: props.currentOrg.id,
            orgDocument: meeting.id,
        }).url,
        {},
        {
            preserveScroll: true,
            onError: () => toast.error('Failed to start extraction.'),
            onFinish: () => processingIds.value.delete(meeting.id),
        },
    );
};

const canProcess = (meeting: StatusMeeting) =>
    !!meeting.content &&
    meeting.ai_draft_status !== 'processing' &&
    !processingIds.value.has(meeting.id);

const formatDate = formatTimestampMdy;

const showUrl = (meeting: StatusMeeting) =>
    orgDocumentsRoutes.show({
        organization: props.currentOrg.id,
        orgDocument: meeting.id,
    }).url;

const docUrl = (doc: StatusMeetingLinkedDocument) =>
    projectDocumentsRoutes.show({ project: doc.project_id, document: doc.id })
        .url;
</script>

<template>
    <Head title="Status Meetings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-8 p-6">
            <!-- Header -->
            <div
                class="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center"
            >
                <div>
                    <h1
                        class="text-2xl font-black tracking-tight text-gray-900 dark:text-white"
                    >
                        Status Meetings
                    </h1>
                    <p class="mt-0.5 text-sm text-gray-500">
                        Cross-project meeting notes and action items for
                        {{ currentOrg.name }}.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <Button
                        v-if="canManage"
                        @click="
                            router.visit(
                                orgDocumentsRoutes.create({
                                    organization: currentOrg.id,
                                }).url,
                            )
                        "
                        class="h-10 px-5 font-bold"
                    >
                        <Plus class="mr-2 h-4 w-4" />
                        <span
                            class="text-[10px] font-black tracking-widest uppercase"
                            >New Status Meeting</span
                        >
                    </Button>
                </div>
            </div>

            <!-- Tabs -->
            <div
                class="flex items-center border-b border-gray-200 dark:border-gray-700"
            >
                <button
                    v-for="tab in ['documentation', 'recordings']"
                    :key="tab"
                    @click="activeTab = tab as 'documentation' | 'recordings'"
                    :class="[
                        '-mb-[1px] border-b-2 px-8 py-4 text-[10px] font-black tracking-[0.2em] uppercase transition-all',
                        activeTab === tab
                            ? 'border-projector-primary-500 text-projector-primary-600'
                            : 'border-transparent text-gray-400 hover:text-gray-600',
                    ]"
                >
                    {{
                        tab === 'documentation' ? 'Documentation' : 'Recordings'
                    }}
                </button>
            </div>

            <!-- Documentation Tab -->
            <div v-show="activeTab === 'documentation'">
                <div
                    v-if="statusMeetings.length === 0"
                    class="space-y-3 rounded-3xl border-2 border-dashed border-gray-100 py-20 text-center dark:border-gray-800/50"
                >
                    <div class="flex justify-center">
                        <div
                            class="rounded-full bg-gray-100 p-4 dark:bg-zinc-800"
                        >
                            <CalendarDays class="h-8 w-8 text-gray-400" />
                        </div>
                    </div>
                    <p class="font-bold text-gray-500">
                        No status meetings yet
                    </p>
                    <p class="text-sm text-gray-400">
                        Create a status meeting to capture notes across multiple
                        projects.
                    </p>
                </div>

                <div v-else class="grid gap-3">
                    <div
                        v-for="meeting in statusMeetings"
                        :key="meeting.id"
                        class="flex flex-col"
                    >
                        <!-- Meeting row -->
                        <div
                            :class="[
                                'mb-1 flex h-12 min-w-0 cursor-pointer items-center rounded-md px-2 transition-colors',
                                FLAT_ROW_HOVER,
                            ]"
                            @click="router.visit(showUrl(meeting))"
                        >
                            <!-- Expand toggle -->
                            <div
                                v-if="hasChildren(meeting)"
                                class="mr-2 flex h-6 w-6 shrink-0 cursor-pointer items-center justify-center rounded-md hover:bg-slate-200/80 dark:hover:bg-slate-700/60"
                                @click.stop="toggle(meeting.id)"
                            >
                                <ChevronRight
                                    class="h-4 w-4 text-slate-400 transition-transform duration-300"
                                    :class="{
                                        'rotate-90': isExpanded(meeting.id),
                                    }"
                                />
                            </div>
                            <div v-else class="mr-2 w-6 shrink-0" />

                            <IconTile :icon="FileText" size="sm" />

                            <!-- Name + meta -->
                            <div
                                class="mx-4 flex min-w-0 flex-1 items-center gap-3 overflow-hidden"
                            >
                                <span
                                    class="truncate text-sm font-bold tracking-tight text-slate-900 dark:text-slate-100"
                                >
                                    {{ meeting.name }}
                                </span>

                                <!-- Processing indicator -->
                                <div
                                    v-if="
                                        meeting.ai_draft_status === 'processing'
                                    "
                                    class="flex shrink-0 items-center gap-1.5"
                                >
                                    <RefreshCw
                                        class="h-3 w-3 animate-spin text-projector-primary-500"
                                    />
                                    <span
                                        class="animate-pulse text-[10px] font-black tracking-widest text-projector-primary-500 uppercase"
                                        >Processing…</span
                                    >
                                </div>

                                <!-- Pending review badge -->
                                <span
                                    v-else-if="
                                        meeting.ai_draft_status ===
                                        'pending_review'
                                    "
                                    class="shrink-0 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[9px] font-black tracking-widest text-amber-600 uppercase dark:border-amber-800 dark:bg-amber-950/30"
                                >
                                    Pending Review
                                </span>

                                <span
                                    class="shrink-0 text-[9px] font-black tracking-widest text-slate-400 uppercase"
                                >
                                    Status Meeting
                                </span>
                            </div>

                            <!-- Date + creator -->
                            <div
                                class="mr-4 hidden shrink-0 items-center gap-2 text-[11px] text-slate-400 md:flex"
                            >
                                <span v-if="meeting.creator">{{
                                    meeting.creator.name
                                }}</span>
                                <span
                                    v-if="meeting.creator"
                                    class="text-slate-300"
                                    >·</span
                                >
                                <span>{{
                                    formatDate(meeting.created_at)
                                }}</span>
                            </div>

                            <!-- Action buttons -->
                            <div
                                class="flex shrink-0 items-center gap-2"
                                @click.stop
                            >
                                <!-- Process / Reprocess -->
                                <Button
                                    v-if="
                                        canManage &&
                                        meeting.ai_draft_status !==
                                            'processing' &&
                                        meeting.ai_draft_status !==
                                            'pending_review'
                                    "
                                    variant="ghost"
                                    size="sm"
                                    :disabled="!canProcess(meeting)"
                                    @click="triggerProcess(meeting)"
                                    class="h-8 rounded-md bg-violet-50 px-3 text-violet-700 dark:bg-violet-950/30 dark:text-violet-400"
                                >
                                    <Sparkles class="mr-1.5 h-3.5 w-3.5" />
                                    <span
                                        class="text-[10px] font-black tracking-wider uppercase"
                                    >
                                        {{
                                            meeting.ai_draft_status ===
                                            'committed'
                                                ? 'Reprocess'
                                                : 'Process'
                                        }}
                                    </span>
                                </Button>

                                <!-- Review (pending_review) -->
                                <Button
                                    v-if="
                                        meeting.ai_draft_status ===
                                        'pending_review'
                                    "
                                    variant="ghost"
                                    size="sm"
                                    @click="
                                        meeting.ai_draft_groups.length
                                            ? router.visit(
                                                  orgDocumentsDraftRoutes.show({
                                                      organization:
                                                          currentOrg.id,
                                                      orgDocument: meeting.id,
                                                      groupId:
                                                          meeting
                                                              .ai_draft_groups[0]
                                                              .group_id,
                                                  }).url,
                                              )
                                            : router.visit(showUrl(meeting))
                                    "
                                    class="h-8 rounded-md bg-amber-50 px-3 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400"
                                >
                                    <Sparkles class="mr-1.5 h-3.5 w-3.5" />
                                    <span
                                        class="text-[10px] font-black tracking-wider uppercase"
                                        >Review</span
                                    >
                                </Button>

                                <!-- View -->
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="router.visit(showUrl(meeting))"
                                    class="h-8 rounded-md bg-slate-50 px-3 text-slate-600 dark:bg-slate-800 dark:text-slate-400"
                                >
                                    <Eye class="mr-1.5 h-3.5 w-3.5" />
                                    <span
                                        class="text-[10px] font-black tracking-wider uppercase"
                                        >View</span
                                    >
                                </Button>
                            </div>
                        </div>

                        <!-- Children (expanded) -->
                        <div v-if="isExpanded(meeting.id)" class="w-full">
                            <!-- Draft groups (pending_review) -->
                            <template
                                v-if="
                                    meeting.ai_draft_status === 'pending_review'
                                "
                            >
                                <div
                                    v-for="group in meeting.ai_draft_groups"
                                    :key="group.group_id"
                                    :class="[
                                        'mb-1 ml-8 flex h-9 min-w-0 cursor-pointer items-center gap-3 rounded-md px-2 transition-colors',
                                        FLAT_ROW_HOVER,
                                    ]"
                                    @click="
                                        router.visit(
                                            orgDocumentsDraftRoutes.show({
                                                organization: currentOrg.id,
                                                orgDocument: meeting.id,
                                                groupId: group.group_id,
                                            }).url,
                                        )
                                    "
                                >
                                    <FileText
                                        class="h-3.5 w-3.5 shrink-0 text-amber-500"
                                    />
                                    <div
                                        class="flex min-w-0 flex-1 items-center gap-3 overflow-hidden"
                                    >
                                        <span
                                            class="truncate text-sm font-semibold text-slate-700 dark:text-slate-300"
                                        >
                                            {{
                                                group.document_title ||
                                                group.project_name
                                            }}
                                        </span>
                                        <span
                                            class="shrink-0 rounded-full bg-amber-50 px-1.5 py-0.5 text-[9px] font-black tracking-widest text-amber-500 uppercase dark:bg-amber-950/30"
                                        >
                                            Draft
                                        </span>
                                        <span
                                            v-if="group.client_name"
                                            class="shrink-0 text-[10px] text-slate-400"
                                        >
                                            {{ group.client_name }}
                                        </span>
                                    </div>
                                </div>
                            </template>

                            <!-- Committed linked documents -->
                            <div
                                v-for="doc in meeting.linked_documents"
                                :key="doc.id"
                                :class="[
                                    'mb-1 ml-8 flex h-9 min-w-0 cursor-pointer items-center gap-3 rounded-md px-2 transition-colors',
                                    FLAT_ROW_HOVER,
                                ]"
                                @click="router.visit(docUrl(doc))"
                            >
                                <FileText
                                    class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                />
                                <div
                                    class="flex min-w-0 flex-1 items-center gap-3 overflow-hidden"
                                >
                                    <span
                                        class="truncate text-sm font-semibold text-slate-700 dark:text-slate-300"
                                    >
                                        {{ doc.name }}
                                    </span>
                                    <span
                                        class="shrink-0 text-[9px] font-black tracking-widest text-slate-400 uppercase"
                                    >
                                        Meeting Notes
                                    </span>
                                    <span
                                        v-if="doc.project_name"
                                        class="shrink-0 text-[10px] text-slate-400"
                                    >
                                        {{ doc.project_name
                                        }}<template v-if="doc.client_name">
                                            · {{ doc.client_name }}</template
                                        >
                                    </span>
                                </div>
                                <Eye
                                    class="h-3.5 w-3.5 shrink-0 text-slate-300"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recordings Tab -->
            <div v-show="activeTab === 'recordings'">
                <ImportDocumentOptions
                    :organization-id="currentOrg.id"
                    :can-manage="canManage"
                    :google-picker-configured="googlePickerConfigured"
                    :google-api-key="googleApiKey"
                    :google-app-id="googleAppId"
                />

                <div
                    v-if="!meetingProvider"
                    class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-gray-200 py-16 dark:border-gray-700"
                >
                    <p class="text-sm font-bold text-gray-500">
                        No meeting provider configured
                    </p>
                    <p class="mt-1 text-xs text-gray-400">
                        Configure a provider in Organization Settings to import
                        recordings.
                    </p>
                </div>

                <Deferred v-else data="recordingsData">
                    <template #fallback>
                        <div class="grid gap-0.5">
                            <div
                                v-for="i in 4"
                                :key="i"
                                class="flex h-12 animate-pulse items-center gap-3 px-2"
                            >
                                <div
                                    class="h-3.5 w-3.5 shrink-0 rounded bg-gray-100 dark:bg-gray-800"
                                />
                                <div class="flex flex-1 items-center gap-3">
                                    <div
                                        class="h-3 w-40 rounded bg-gray-100 dark:bg-gray-800"
                                    />
                                    <div
                                        class="h-2.5 w-24 rounded bg-gray-100 dark:bg-gray-800"
                                    />
                                </div>
                                <div
                                    class="h-8 w-20 rounded-md bg-gray-100 dark:bg-gray-800"
                                />
                            </div>
                        </div>
                    </template>

                    <AvailableOrgRecordings
                        :organization-id="currentOrg.id"
                        :recordings="recordingsData!.recordings"
                        :imported-ids="recordingsData!.importedIds"
                        :can-manage="canManage"
                        :provider-error="recordingsData!.providerError"
                    />
                </Deferred>
            </div>
        </div>
    </AppLayout>
</template>

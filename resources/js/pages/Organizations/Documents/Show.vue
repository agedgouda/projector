<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { Head, useForm, usePage, router, Deferred } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import AvailableOrgRecordings from '@/components/AvailableOrgRecordings.vue';
import { ArrowLeft, Edit2, Trash2, X, Sparkles, Loader2, Plus, Check } from 'lucide-vue-next';
import DOMPurify from 'dompurify';
import orgDocumentsRoutes from '@/routes/organizations/documents/index';
import statusMeetingsRoutes from '@/routes/status-meetings/index';
import { type BreadcrumbItem, type Recording } from '@/types';

interface DraftGroup {
    group_id: string;
    project_id: string | null;
    project_name: string;
    client_name: string;
    is_new: boolean;
    document_title: string;
    document_content: string;
}

interface AiDraft {
    status: 'processing' | 'pending_review' | 'committed' | 'failed';
    groups?: DraftGroup[];
    error?: string;
}

interface OrgDocument {
    id: string;
    name: string;
    type: string;
    content: string;
    metadata: Record<string, any> | null;
    processed_at: string | null;
    created_at: string;
    creator?: { name: string } | null;
    editor?: { name: string } | null;
}

interface ActiveProject {
    id: string;
    name: string;
    client_name: string;
}

interface RecordingsData {
    recordings: Recording[];
    importedIds: string[];
    providerError: string | null;
}

const props = defineProps<{
    organization: { id: string; name: string };
    item: OrgDocument;
    canManage: boolean;
    activeProjects: ActiveProject[];
    meetingProvider: string | null;
    recordingsData: RecordingsData;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Status Meetings', href: statusMeetingsRoutes.index.url() },
    { title: props.item.name ?? 'Status Meeting', href: '' },
];

const activeTab = ref<'documentation' | 'action-items' | 'recordings'>('documentation');

onMounted(() => {
    const tab = new URLSearchParams(window.location.search).get('tab');
    if (tab === 'action-items' || tab === 'recordings') {
        activeTab.value = tab;
    }
});
const isEditing = ref(false);
const isDeleteModalOpen = ref(false);
const isDeleting = ref(false);
const isCommitting = ref(false);
const isProcessing = ref(false);

// ── Documentation form ────────────────────────────────────────────────────────

const form = useForm({
    name: props.item.name ?? '',
    type: props.item.type,
    content: props.item.content,
    metadata: props.item.metadata ?? {},
});

const toggleEdit = () => {
    if (isEditing.value) form.reset();
    isEditing.value = !isEditing.value;
};

const handleSubmit = () => {
    form.patch(orgDocumentsRoutes.update({ organization: props.organization.id, orgDocument: props.item.id }).url, {
        onSuccess: () => { toast.success('Status meeting updated.'); isEditing.value = false; },
        onError: () => toast.error('Please correct the errors.'),
    });
};

const handleDelete = () => {
    isDeleting.value = true;
    router.delete(orgDocumentsRoutes.destroy({ organization: props.organization.id, orgDocument: props.item.id }).url, {
        onSuccess: () => toast.success('Status meeting deleted.'),
        onFinish: () => { isDeleting.value = false; },
    });
};

// ── Draft / Action Items ──────────────────────────────────────────────────────

const aiDraft = computed<AiDraft | null>(() => props.item.metadata?.ai_draft ?? null);

// Local mutable copy of groups for the review UI
const draftGroups = ref<DraftGroup[]>([]);

watch(() => props.item.metadata?.ai_draft, (draft) => {
    if (draft?.status === 'pending_review' && draft.groups) {
        draftGroups.value = JSON.parse(JSON.stringify(draft.groups));
    }
}, { immediate: true });

// Poll when processing
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const startPolling = () => {
    pollTimer = setTimeout(() => {
        router.reload({
            only: ['item'],
            onSuccess: () => { if (aiDraft.value?.status === 'processing') startPolling(); },
        });
    }, 4000);
};

watch(aiDraft, (draft) => {
    if (draft?.status === 'processing') {
        startPolling();
    } else if (pollTimer) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
}, { immediate: true });

const canCommit = computed(() =>
    draftGroups.value.length > 0 &&
    draftGroups.value.every(g => g.project_id !== null && g.document_content.trim() !== '')
);

const triggerProcessing = () => {
    isProcessing.value = true;
    router.post(orgDocumentsRoutes.processDraft({ organization: props.organization.id, orgDocument: props.item.id }).url, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
};

const deleteGroup = (groupId: string) => {
    draftGroups.value = draftGroups.value.filter(g => g.group_id !== groupId);
};

const editingGroupId = ref<string | null>(null);

const toggleGroupEdit = (groupId: string) => {
    editingGroupId.value = editingGroupId.value === groupId ? null : groupId;
};

const saveDraftAndNavigate = (url: string) => {
    router.patch(orgDocumentsRoutes.saveDraft({ organization: props.organization.id, orgDocument: props.item.id }).url, {
        groups: draftGroups.value,
    }, {
        preserveScroll: true,
        onSuccess: () => router.visit(url),
    });
};

const commitDraft = () => {
    const committableGroups = draftGroups.value.filter(g => g.project_id !== null && g.document_content.trim() !== '');
    isCommitting.value = true;
    router.post(orgDocumentsRoutes.commitDraft({ organization: props.organization.id, orgDocument: props.item.id }).url, {
        groups: committableGroups,
    }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Action items committed to projects.'),
        onError: (errors) => toast.error(Object.values(errors)[0] as string),
        onFinish: () => { isCommitting.value = false; },
    });
};

// ── Flash ─────────────────────────────────────────────────────────────────────

const page = usePage<{ flash?: { success?: string; error?: string } }>();
watch(() => page.props.flash, (flash) => {
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
}, { deep: true, immediate: true });

const sanitize = (html: string) => DOMPurify.sanitize(html);
</script>

<template>
    <Head :title="item.name ?? 'Status Meeting'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 space-y-8 w-full">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <button
                    @click="router.visit(statusMeetingsRoutes.index.url())"
                    class="flex items-center gap-2 text-sm text-slate-500 hover:text-indigo-600 transition-colors"
                >
                    <ArrowLeft class="w-3 h-3" />
                    Status Meetings
                </button>

                <div v-if="canManage" class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="toggleEdit"
                        class="relative h-8 w-24 text-[10px] font-black uppercase tracking-widest flex items-center justify-center"
                    >
                        <div class="absolute left-3 flex items-center justify-center">
                            <component :is="isEditing ? X : Edit2" class="h-3 w-3" />
                        </div>
                        <span class="ml-4">{{ isEditing ? 'Cancel' : 'Edit' }}</span>
                    </Button>

                    <Button
                        variant="ghost"
                        size="icon"
                        @click="isDeleteModalOpen = true"
                        class="h-8 w-8 text-slate-400 hover:text-red-600 hover:bg-red-50"
                    >
                        <Trash2 class="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex items-center border-b border-gray-200 dark:border-gray-700">
                <button v-for="tab in ['documentation', 'action-items', 'recordings']" :key="tab"
                    @click="activeTab = tab as 'documentation' | 'action-items' | 'recordings'"
                    :class="['px-8 py-4 text-[10px] font-black uppercase tracking-[0.2em] transition-all border-b-2 -mb-[1px]',
                        activeTab === tab ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-400 hover:text-gray-600']">
                    {{ tab === 'documentation' ? 'Documentation' : tab === 'action-items' ? 'Action Items' : 'Recordings' }}
                </button>
            </div>

            <!-- Documentation Tab -->
            <div v-show="activeTab === 'documentation'">
                <div v-if="isEditing" class="max-w-3xl bg-white dark:bg-zinc-900 border border-slate-200 dark:border-zinc-800 rounded-2xl p-8 space-y-5 shadow-sm">
                    <form @submit.prevent="handleSubmit" class="space-y-5">
                        <div class="space-y-1.5">
                            <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Title</label>
                            <input
                                v-model="form.name"
                                type="text"
                                class="w-full rounded-xl border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 px-4 py-2.5 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                            <p v-if="form.errors.name" class="text-xs text-red-500">{{ form.errors.name }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Notes / Transcript</label>
                            <textarea
                                v-model="form.content"
                                rows="16"
                                class="w-full rounded-xl border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 px-4 py-3 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-y font-mono leading-relaxed"
                            />
                            <p v-if="form.errors.content" class="text-xs text-red-500">{{ form.errors.content }}</p>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="outline" @click="toggleEdit">Cancel</Button>
                            <Button type="submit" :disabled="form.processing" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 rounded-xl">
                                {{ form.processing ? 'Saving...' : 'Save Changes' }}
                            </Button>
                        </div>
                    </form>
                </div>

                <div v-else class="max-w-3xl bg-white dark:bg-zinc-900 border border-slate-200 dark:border-zinc-800 rounded-2xl p-8 shadow-sm space-y-4">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-indigo-500 mb-1">Status Meeting</p>
                        <h1 class="text-2xl font-black tracking-tight text-slate-900 dark:text-white">{{ item.name }}</h1>
                        <p v-if="item.creator" class="text-xs text-slate-400 mt-1">Created by {{ item.creator.name }}</p>
                    </div>
                    <div
                        class="text-[15px] text-slate-900 dark:text-slate-400 leading-relaxed prose prose-slate dark:prose-invert max-w-none whitespace-pre-wrap"
                        v-html="sanitize(item.content)"
                    />
                </div>
            </div>

            <!-- Action Items Tab -->
            <div v-show="activeTab === 'action-items'">

                <!-- Not yet processed / failed / extracted but empty -->
                <div v-if="!aiDraft || aiDraft.status === 'failed' || (aiDraft.status === 'pending_review' && draftGroups.length === 0)" class="max-w-3xl">
                    <div class="flex flex-col items-center justify-center py-20 border-2 border-dashed rounded-3xl border-gray-100 dark:border-gray-800/50 space-y-4">
                        <div class="p-4 bg-indigo-50 dark:bg-indigo-950 rounded-full">
                            <Sparkles class="w-8 h-8 text-indigo-400" />
                        </div>
                        <div class="text-center space-y-1">
                            <p class="font-bold text-gray-700 dark:text-gray-300">Extract Action Items</p>
                            <p class="text-sm text-gray-400 max-w-xs">AI will identify action items from this meeting and group them by project for your review.</p>
                            <template v-if="aiDraft?.status === 'failed'">
                                <p class="text-xs text-red-500 mt-2">Extraction failed. Try again.</p>
                                <p v-if="aiDraft.error" class="text-xs text-red-400 mt-1 font-mono max-w-sm break-words">{{ aiDraft.error }}</p>
                            </template>
                            <p v-else-if="aiDraft?.status === 'pending_review' && draftGroups.length === 0" class="text-xs text-amber-500 mt-2">No action items were extracted. Try again.</p>
                        </div>
                        <Button
                            v-if="canManage"
                            :disabled="isProcessing || !item.content"
                            @click="triggerProcessing"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl px-6 h-10 text-[10px] font-black uppercase tracking-widest"
                        >
                            <Loader2 v-if="isProcessing" class="w-3 h-3 mr-2 animate-spin" />
                            <Sparkles v-else class="w-3 h-3 mr-2" />
                            {{ isProcessing ? 'Starting…' : 'Extract Action Items' }}
                        </Button>
                    </div>
                </div>

                <!-- Processing -->
                <div v-else-if="aiDraft.status === 'processing'" class="max-w-3xl space-y-3">
                    <div class="flex items-center gap-3 px-1 mb-6">
                        <Loader2 class="w-4 h-4 animate-spin text-indigo-500 shrink-0" />
                        <p class="text-sm font-bold text-gray-600 dark:text-gray-400">Analyzing transcript and extracting action items…</p>
                    </div>
                    <div v-for="i in 3" :key="i" class="bg-white dark:bg-zinc-900 border border-gray-100 dark:border-zinc-800 rounded-2xl p-6 animate-pulse space-y-3">
                        <div class="h-3 bg-gray-100 dark:bg-zinc-800 rounded w-1/3" />
                        <div class="space-y-2 pt-1">
                            <div class="h-2.5 bg-gray-100 dark:bg-zinc-800 rounded w-3/4" />
                            <div class="h-2.5 bg-gray-100 dark:bg-zinc-800 rounded w-1/2" />
                        </div>
                    </div>
                </div>

                <!-- Committed -->
                <div v-else-if="aiDraft.status === 'committed'" class="max-w-3xl">
                    <div class="flex flex-col items-center justify-center py-20 border-2 border-dashed rounded-3xl border-green-100 dark:border-green-900/30 space-y-3">
                        <div class="p-4 bg-green-50 dark:bg-green-950 rounded-full">
                            <Check class="w-8 h-8 text-green-500" />
                        </div>
                        <p class="font-bold text-gray-700 dark:text-gray-300">Action items committed to projects</p>
                        <Button
                            v-if="canManage"
                            variant="outline"
                            @click="triggerProcessing"
                            class="text-[10px] font-black uppercase tracking-widest rounded-xl"
                        >
                            <Sparkles class="w-3 h-3 mr-2" />
                            Re-extract
                        </Button>
                    </div>
                </div>

                <!-- Pending Review -->
                <div v-else-if="aiDraft.status === 'pending_review'" class="max-w-3xl space-y-6">
                    <div class="flex items-center justify-between">
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Review & Edit Before Committing</p>
                        <Button
                            :disabled="!canCommit || isCommitting"
                            @click="commitDraft"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl px-5 h-9 text-[10px] font-black uppercase tracking-widest"
                        >
                            <Loader2 v-if="isCommitting" class="w-3 h-3 mr-2 animate-spin" />
                            <Check v-else class="w-3 h-3 mr-2" />
                            {{ isCommitting ? 'Committing…' : 'Commit to Projects' }}
                        </Button>
                    </div>

                    <!-- Document per project -->
                    <div
                        v-for="group in draftGroups"
                        :key="group.group_id"
                        class="bg-white dark:bg-zinc-900 border rounded-2xl overflow-hidden shadow-sm"
                        :class="group.project_id ? 'border-gray-200 dark:border-zinc-800' : 'border-amber-200 dark:border-amber-900/50'"
                    >
                        <!-- Group header -->
                        <div class="px-6 py-4 border-b flex items-center justify-between gap-4"
                            :class="group.project_id ? 'border-gray-100 dark:border-zinc-800 bg-gray-50/50 dark:bg-zinc-800/30' : 'border-amber-100 dark:border-amber-900/30 bg-amber-50/50 dark:bg-amber-950/20'">
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-black uppercase tracking-widest"
                                    :class="group.project_id ? 'text-indigo-500' : 'text-amber-500'">
                                    {{ group.client_name || 'Unknown Client' }}
                                </p>
                                <p class="font-bold text-sm text-gray-900 dark:text-white mt-0.5 flex items-center gap-2">
                                    {{ group.project_name }}
                                    <span v-if="!group.project_id" class="text-[10px] font-black uppercase tracking-widest text-amber-500 bg-amber-100 dark:bg-amber-900/30 px-2 py-0.5 rounded-full">Unmatched</span>
                                </p>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <!-- Assign to existing project (for unmatched groups) -->
                                <template v-if="!group.project_id">
                                    <select
                                        class="h-8 rounded-lg border border-amber-200 dark:border-amber-800 bg-white dark:bg-zinc-900 px-2 text-xs font-medium text-gray-700 dark:text-gray-300 focus:outline-none focus:ring-2 focus:ring-amber-400"
                                        @change="(e) => { group.project_id = (e.target as HTMLSelectElement).value || null; group.is_new = false; }"
                                    >
                                        <option value="">Assign to project…</option>
                                        <option v-for="p in activeProjects" :key="p.id" :value="p.id">{{ p.name }} ({{ p.client_name }})</option>
                                    </select>
                                    <Button
                                        v-if="canManage"
                                        size="sm"
                                        variant="ghost"
                                        class="text-[10px] font-black uppercase tracking-widest text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-950 rounded-lg h-8 px-3"
                                        @click="saveDraftAndNavigate(`/projects/create?name=${encodeURIComponent(group.project_name)}&client=${encodeURIComponent(group.client_name)}`)"
                                    >
                                        <Plus class="w-3 h-3 mr-1" /> Create Project
                                    </Button>
                                </template>

                                <button
                                    @click="toggleGroupEdit(group.group_id)"
                                    class="p-1.5 rounded-lg text-gray-400 hover:text-indigo-500 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 transition-colors"
                                    :title="editingGroupId === group.group_id ? 'Done editing' : 'Edit document'"
                                >
                                    <Check v-if="editingGroupId === group.group_id" class="w-4 h-4 text-green-500" />
                                    <Edit2 v-else class="w-4 h-4" />
                                </button>

                                <button
                                    @click="deleteGroup(group.group_id)"
                                    class="p-1.5 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 transition-colors"
                                    title="Remove this document"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </button>
                            </div>
                        </div>

                        <!-- Edit mode -->
                        <div v-if="editingGroupId === group.group_id" class="p-6 space-y-3">
                            <input
                                v-model="group.document_title"
                                class="w-full rounded-lg border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 px-3 py-2 text-sm font-bold text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                placeholder="Document title"
                            />
                            <textarea
                                v-model="group.document_content"
                                rows="16"
                                class="w-full rounded-lg border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 px-3 py-2 text-xs text-slate-700 dark:text-slate-300 font-mono leading-relaxed focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-y"
                            />
                        </div>

                        <!-- Preview mode -->
                        <div
                            v-else
                            class="px-8 py-6 prose-content"
                            v-html="sanitize(group.document_content)"
                        />
                    </div>

                    <div class="flex justify-end pt-2">
                        <Button
                            :disabled="!canCommit || isCommitting"
                            @click="commitDraft"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl px-6 h-10 text-[10px] font-black uppercase tracking-widest"
                        >
                            <Loader2 v-if="isCommitting" class="w-3 h-3 mr-2 animate-spin" />
                            <Check v-else class="w-3 h-3 mr-2" />
                            {{ isCommitting ? 'Committing…' : 'Commit to Projects' }}
                        </Button>
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
                        <div class="space-y-3">
                            <div v-for="i in 4" :key="i" class="flex items-center gap-4 p-4 rounded-2xl border border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 animate-pulse">
                                <div class="w-8 h-8 rounded-xl bg-gray-100 dark:bg-gray-800 shrink-0" />
                                <div class="flex-1 space-y-2">
                                    <div class="h-3 bg-gray-100 dark:bg-gray-800 rounded w-1/3" />
                                    <div class="h-2.5 bg-gray-100 dark:bg-gray-800 rounded w-1/5" />
                                </div>
                                <div class="h-9 w-24 bg-gray-100 dark:bg-gray-800 rounded-xl" />
                            </div>
                        </div>
                    </template>

                    <AvailableOrgRecordings
                        :organization-id="organization.id"
                        :org-document-id="item.id"
                        :recordings="recordingsData.recordings"
                        :imported-ids="recordingsData.importedIds"
                        :can-manage="canManage"
                        :provider-error="recordingsData.providerError"
                    />
                </Deferred>
            </div>
        </div>

        <ConfirmDeleteModal
            :open="isDeleteModalOpen"
            title="Delete Status Meeting"
            :description="`Are you sure you want to delete '${item.name}'? This action cannot be undone.`"
            :loading="isDeleting"
            @confirm="handleDelete"
            @close="isDeleteModalOpen = false"
        />
    </AppLayout>
</template>

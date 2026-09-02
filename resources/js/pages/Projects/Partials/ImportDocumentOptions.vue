<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { Loader2 } from 'lucide-vue-next';
import { toast } from 'vue-sonner';
import AvailableRecordings from '@/pages/Projects/Partials/AvailableRecordings.vue';
import ImportConfirmModal from '@/components/recordings/ImportConfirmModal.vue';
import ImportDocumentOptionsPanel from '@/components/recordings/ImportDocumentOptionsPanel.vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useGooglePicker } from '@/composables/transcripts/useGooglePicker';
import { useDocumentImportActions, type ImportTypeChoice } from '@/composables/transcripts/useDocumentImportActions';
import { INTAKE_KEY } from '@/composables/useWorkflow';
import { documentTypeLabel, visibleDocumentTypeKeys } from '@/lib/documentTypes';
import transcriptRoutes from '@/routes/projects/transcripts/index';

const NEW_TYPE_VALUE = '__new__';

const props = withDefaults(defineProps<{
    projectId: string;
    canManage: boolean;
    googlePickerConfigured: boolean;
    googleApiKey: string | null;
    googleAppId: string | null;
    // The actual documents already in this project — not the org's curated
    // document_type_definitions catalog — is what decides which types are offered below (see
    // typeOptions). documentTypeCatalog is still consulted, but only for two narrower things:
    // resolving a type key to a nicer label when it has one, and knowing which types are Tasks
    // (excluded, same as Events — both belong to other tabs, not Documentation). Both optional
    // (defaulted below) because Projects/Transcripts.vue, a separate older standalone page that
    // reuses this same modal, doesn't pass either.
    documents?: ProjectDocument[];
    documentTypeCatalog?: DocumentSchemaItem[];
    // Known synchronously (Show.vue already has the organization's own meeting_provider
    // setting), unlike the recordings list itself — lets the heading show immediately when the
    // modal opens instead of waiting on the async fetch below just to learn whether there's a
    // provider at all.
    meetingProvider?: string | null;
}>(), {
    documents: () => [],
    documentTypeCatalog: () => [],
    meetingProvider: null,
});

const { isOpening, openPicker } = useGooglePicker();
const { importingGoogleDoc, importGoogleDoc, importingFile, importFile } = useDocumentImportActions(props.projectId);

const modalOpen = ref(false);

// The Documentation tab's "Import Document" button (Projects/Show.vue) calls this to open the
// modal — see openTaskImport()/openEventImport() in ImportTaskListOptions.vue for the same
// pattern applied to task/event list import.
defineExpose({
    openImportModal: () => {
        modalOpen.value = true;
    },
});

// What type the imported document becomes — the types actually already in use in this
// project (whatever the Documentation tab itself is currently showing folders for), not a
// separate curated list: a type a previous import created by naming it via "Other" below
// becomes a document of that type, which makes it show up here from then on automatically,
// with no extra bookkeeping needed. "Other" itself is always last, for naming a new one. Only
// the intake type ever triggers the universal Notes -> Action Items AI step
// (DocumentObserver::created()); every other type — Other's brand-new one included — is
// treated as already-finished content and skips it (see DocumentTypeResolver::resolve()).
// Applies identically to all three import sources below (Google Doc, file, recording) — see
// handleItemPicked.
const typeOptions = computed(() => {
    const used = visibleDocumentTypeKeys(props.documents, props.documentTypeCatalog);
    // Always offered even for a project with none currently visible (every existing one
    // already processed into something else, or none imported yet) — it's the baseline "this
    // needs cleanup" choice every import had before this picker existed, not something that
    // should disappear just because nothing is sitting unprocessed at this exact moment.
    const keys = used.includes(INTAKE_KEY) ? used : [INTAKE_KEY, ...used];

    return keys.map((key) => ({ key, label: documentTypeLabel(key, props.documentTypeCatalog) }));
});

// A handful of pill-style radio buttons reads fine; once "Other" would make a sixth (or
// later) option, that stops being a row of buttons and starts being a wall of text, so it
// collapses into a dropdown instead — same choice, just laid out differently.
const useDropdown = computed(() => typeOptions.value.length + 1 > 4);

// The intake type is always in typeOptions (see above), so this is always a valid default —
// matches what every import did before this picker existed.
const selectedType = ref<string>(INTAKE_KEY);
const newTypeLabel = ref('');
const isAddingNewType = computed(() => selectedType.value === NEW_TYPE_VALUE);
const skipProcessing = computed(() => selectedType.value !== INTAKE_KEY);

const typeChoice = computed<ImportTypeChoice>(() =>
    isAddingNewType.value ? { newTypeLabel: newTypeLabel.value.trim() } : { type: selectedType.value },
);

// Nothing valid to submit while "Other" is picked but not yet named.
const typeChoiceIncomplete = computed(() => isAddingNewType.value && newTypeLabel.value.trim() === '');

// Every source (Google Doc, file, recording) funnels through this once we actually know what's
// being imported — the one place that decides whether the shared confirmation dialog below is
// needed at all. Only Transcription pauses for it (that's the only type with an AI-generation
// step worth confirming, same reason the dialog exists on the Transcripts tab in the first
// place); every other type imports immediately, identically across all three sources. A picked
// recording follows this exact same rule too — see AvailableRecordings.vue's own copy of this
// same gate, since a recording row's click can't route through this component's state directly.
const pendingImport = ref<{ title: string; run: (prompt: string | null) => void } | null>(null);
const isImportConfirmOpen = ref(false);
const importConfirmLoading = computed(() => importingGoogleDoc.value || importingFile.value !== null);

const handleItemPicked = (title: string, run: (prompt: string | null) => void) => {
    if (skipProcessing.value) {
        run(null);
        return;
    }
    pendingImport.value = { title, run };
    isImportConfirmOpen.value = true;
};

const closeImportConfirm = () => {
    isImportConfirmOpen.value = false;
};

const confirmImport = (additionalInfo: string | null) => {
    pendingImport.value?.run(additionalInfo);
    isImportConfirmOpen.value = false;
};

const handleFilePicked = (file: File, kind: 'docx' | 'txt') => {
    handleItemPicked(file.name, (prompt) => importFile(file, kind, prompt, typeChoice.value));
};

// Unlike the export flows elsewhere in the app, picking a file can't survive a redirect —
// so this always fetches (and, if needed, connects) *before* ever opening the Picker, never
// mid-pick. On success it opens the Picker directly, so a fresh connect only ever means one
// extra round trip back to this exact state, not a resumed selection.
const startGoogleDocImport = async () => {
    try {
        const response = await axios.get<{ access_token: string }>(
            transcriptRoutes.googlePickerToken.url(props.projectId)
        );

        await openPicker({
            accessToken: response.data.access_token,
            apiKey: props.googleApiKey ?? '',
            appId: props.googleAppId ?? '',
            onPicked: (file) => handleItemPicked(file.name, (prompt) => importGoogleDoc(file, prompt, typeChoice.value)),
        });
    } catch (err) {
        if (axios.isAxiosError(err) && err.response?.status === 428 && err.response.data?.connect_url) {
            const returnUrl = new URL(window.location.href);
            returnUrl.searchParams.set('google_doc_import', '1');

            const connectUrl = new URL(err.response.data.connect_url);
            connectUrl.searchParams.set('return_to', returnUrl.pathname + returnUrl.search);

            window.location.href = connectUrl.toString();
            return;
        }
        toast.error('Could not connect to Google. Please try again.');
    }
};

onMounted(() => {
    if (new URLSearchParams(window.location.search).get('google_doc_import') === '1') {
        const url = new URL(window.location.href);
        url.searchParams.delete('google_doc_import');
        window.history.replaceState(window.history.state, '', url);
        modalOpen.value = true;
        void startGoogleDocImport();
    }
});

// The recordings picker's data used to arrive as a prop sourced from the surrounding page's
// own deferred Inertia prop (Projects/Show.vue's recordingsData) — that tied its freshness to
// whatever tab-switch/reload history that specific page instance happened to have gone
// through, which was the actual cause of it sometimes just not showing. Fetched directly here
// instead, every time the modal opens, so it's fully self-contained regardless of how long the
// underlying page has been sitting open or which tab got clicked first.
const recordingsState = ref<{
    recordings: Recording[];
    importedIds: string[];
    crossProjectImportedIds: string[];
    providerError: string | null;
    provider: string | null;
    canManageTranscripts: boolean;
} | undefined>(undefined);

watch(modalOpen, async (open) => {
    if (!open) return;

    recordingsState.value = undefined;
    try {
        const response = await axios.get(transcriptRoutes.available.url(props.projectId));
        recordingsState.value = response.data;
    } catch {
        toast.error('Could not load available recordings.');
    }
});
</script>

<template>
    <Dialog :open="modalOpen" @update:open="modalOpen = $event">
        <DialogContent class="sm:max-w-[560px]">
            <DialogHeader>
                <DialogTitle>Import a Document</DialogTitle>
            </DialogHeader>

            <div class="mb-5">
                <Label class="mb-2 block text-[10px] font-black tracking-widest text-gray-400 uppercase">
                    Import As
                </Label>
                <RadioGroup v-if="!useDropdown" v-model="selectedType" class="flex flex-row flex-wrap gap-x-6 gap-y-2">
                    <div v-for="option in typeOptions" :key="option.key" class="flex items-center gap-2">
                        <RadioGroupItem :id="`import-type-${option.key}`" :value="option.key" />
                        <Label :for="`import-type-${option.key}`" class="text-[13px] font-medium text-slate-600 dark:text-slate-300">
                            {{ option.label }}
                        </Label>
                    </div>
                    <div class="flex items-center gap-2">
                        <RadioGroupItem id="import-type-new" :value="NEW_TYPE_VALUE" />
                        <Label for="import-type-new" class="text-[13px] font-medium text-slate-600 dark:text-slate-300">
                            Other
                        </Label>
                    </div>
                </RadioGroup>
                <Select v-else :model-value="selectedType" @update:model-value="(v) => selectedType = (v as string)">
                    <SelectTrigger class="h-9 w-full text-[13px]">
                        <SelectValue placeholder="Select..." />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="option in typeOptions" :key="option.key" :value="option.key">
                            {{ option.label }}
                        </SelectItem>
                        <SelectItem :value="NEW_TYPE_VALUE">Other...</SelectItem>
                    </SelectContent>
                </Select>
                <Input
                    v-if="isAddingNewType"
                    v-model="newTypeLabel"
                    placeholder="New type name"
                    class="mt-2 h-9 text-[13px]"
                />
            </div>

            <!-- DialogContent lays its direct children out in a `grid gap-4`, so the panel and
                 the recordings block below are wrapped in one div here — otherwise that 16px
                 grid gap lands between them in addition to any margin either piece sets,
                 making a loaded recording row sit much further from "Upload Word or Text
                 File" than that row sits from "Import from Google Docs" above it. Wrapped
                 together, this whole group is a single grid child, so ordinary block-flow
                 margins inside it (mt-0.5 below) are the only spacing in play. -->
            <div>
                <ImportDocumentOptionsPanel
                    heading=""
                    spacing-class="mb-0"
                    :can-manage="canManage"
                    :google-picker-configured="googlePickerConfigured"
                    :is-opening="isOpening"
                    :importing-google-doc="importingGoogleDoc"
                    :importing-file="importingFile"
                    :disabled="typeChoiceIncomplete"
                    @pick-google-doc="startGoogleDocImport"
                    @pick-docx-file="(file) => handleFilePicked(file, 'docx')"
                    @pick-txt-file="(file) => handleFilePicked(file, 'txt')"
                />

                <!-- Always shown whenever the org has a transcription source. A picked recording
                     goes through the same type picker above as Google Docs/files do (typeChoice
                     below) — DocumentTypeResolver decides intake vs. everything else identically
                     regardless of source, and AvailableRecordings.vue shows the exact same
                     confirmation dialog (ImportConfirmModal) this component does, under the same
                     condition, so all three sources behave identically. No section heading — text
                     and spinner while loading, then straight into the recording rows themselves.
                     mt-0.5 matches the gap-0.5 between the panel's own two rows above, so a
                     recording row reads as one more row in that same list rather than a separate
                     section. Reuses the exact same import flow the Transcripts tab itself uses
                     (AvailableRecordings.vue), nothing new to keep in sync. -->
                <div v-if="meetingProvider" class="mt-0.5">
                    <div v-if="recordingsState === undefined" class="flex flex-col items-center justify-center py-12">
                        <p class="mb-3 text-sm font-medium text-slate-600 dark:text-slate-300">
                            Checking for Available Recordings
                        </p>
                        <Loader2 class="h-8 w-8 animate-spin text-projector-primary-500" />
                    </div>
                    <AvailableRecordings
                        v-else
                        :project-id="projectId"
                        :recordings="recordingsState.recordings"
                        :imported-ids="recordingsState.importedIds"
                        :cross-project-imported-ids="recordingsState.crossProjectImportedIds"
                        :can-manage="recordingsState.canManageTranscripts"
                        :provider-error="recordingsState.providerError"
                        :type-choice="typeChoice"
                    />
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <!-- The one confirmation dialog for Google Doc/file imports of type Transcription —
         AvailableRecordings.vue renders its own instance of this exact same component for a
         picked recording, under the identical condition (see handleItemPicked above), so a
         user sees byte-for-byte the same dialog no matter which of the three sources they
         picked. -->
    <ImportConfirmModal
        :open="isImportConfirmOpen"
        :item-title="pendingImport?.title"
        :loading="importConfirmLoading"
        @close="closeImportConfirm"
        @confirm="confirmImport"
    />
</template>

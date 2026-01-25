<script setup lang="ts">
import { ref, toRef, watch, computed } from 'vue'; // Added computed
import { router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { useDocumentActions } from '@/composables/useDocumentActions';
import { useProjectState } from '@/composables/useProjectState';
import { useAiProcessing } from '@/composables/useAiProcessing';
import { PlusIcon, Search, RefreshCw } from 'lucide-vue-next';

// UI Components
import AppLogoIcon from '@/components/AppLogoIcon.vue'
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Alert, AlertTitle, AlertDescription } from "@/components/ui/alert";
import TraceabilityRow from './TraceabilityRow.vue';
import TraceabilityDetailSheet from './TraceabilityDetailSheet.vue';

const props = defineProps<{
    project: any;
    requirementStatus: any[];
    canGenerate: boolean;
    isGenerating: boolean;
    projectDocumentsRoutes: any;
}>();

const emit = defineEmits(['confirmDelete', 'generate']);

// --- 1. ENCAPSULATED STATE ---
const {
    documentsMap,
    allDocs,
    localRequirements,
    searchQuery,
    expandedRootIds,
    documentTree,
    toggleRoot,
    updateDocument // This is our unified funnel
} = useProjectState(toRef(props, 'requirementStatus'));

// --- 2. ACTION LOGIC ---
const aiStatusMessageRef = ref('');


const {
    form,
    updateDocument: originalUpdateDocument, setDocToProcessing,
    targetBeingCreated, editingDocumentId
} = useDocumentActions(
    props,
    localRequirements,
    aiStatusMessageRef,
    updateDocument // 🚀 Pass the funnel here
);

// --- 3. ENCAPSULATED AI & REAL-TIME ---
const { aiStatusMessage, aiProgress, isAiProcessing } = useAiProcessing(
    props.project.id,
    allDocs,
    targetBeingCreated,
    // CALLBACK: On Echo Update
    (incomingDoc: ExtendedDocument) => {
        // 1. Update data & expand tree via the funnel
        updateDocument(incomingDoc.id, incomingDoc);

        // 2. Business Logic: If this is the doc we were waiting for, clear the "Creating" state
        if (targetBeingCreated.value === incomingDoc.type) {
            targetBeingCreated.value = null;
        }
    },
    // CALLBACK: On Success
    () => {
        toast.success('Processing Complete', { description: 'Document has been successfully analyzed.' });
        // Target is now cleared in the update callback above for better precision
    },
    // CALLBACK: On Error
    (errorMessage) => {
        toast.error('AI Processing Failed', { description: errorMessage });
        targetBeingCreated.value = null; // Clear on error so UI doesn't hang
    }
);

// Sync the local ref used by actions with the composable's state
watch(aiStatusMessage, (val) => aiStatusMessageRef.value = val);

// --- 4. UI LOCAL STATE & METHODS ---
const activeEditingId = ref<string | number | null>(null);
const selectedSheetId = ref<string | number | null>(null); // Track ID for reactive lookup
const isDetailsSheetOpen = ref(false);

// Use a computed property so the sheet always gets the live object from the Map
const selectedSheetItem = computed(() => {
    if (!selectedSheetId.value) return null;
    const doc = documentsMap.value.get(selectedSheetId.value);
    return (doc as ExtendedDocument) || null;
});


const handlePrepareEdit = (item: any) => {
    if (!item || item.id === null) {
        activeEditingId.value = null;
        if (editingDocumentId) editingDocumentId.value = null;
        form.reset();
        return;
    }
    activeEditingId.value = item.id;
    if (editingDocumentId) editingDocumentId.value = item.id;
    form.name = item.name;
    form.content = item.content;
    form.metadata = item.metadata;
    form.type = item.type;
    form.assignee_id = item.assignee_id;
};

const handleUpdateDocument = (callbackFromRow?: () => void) => {
    const docId = activeEditingId.value;
    const formData = form.data();

    // Find the full user object to "hydrate" the local state
    const selectedUser = props.project.client?.users?.find(
        (u: User) => u.id === formData.assignee_id
    ) || null;

    const updatedData = {
        ...JSON.parse(JSON.stringify(formData)),
        assignee: selectedUser
    };

    originalUpdateDocument(() => {
        if (docId) {
            updateDocument(docId, updatedData);
        }

        activeEditingId.value = null;
        if (callbackFromRow) callbackFromRow();
    });
};

const getDocLabel = (typeKey: string) => {
    const schema = props.project.type.document_schema || [];
    return schema.find((item: any) => item.key === typeKey)?.label || typeKey.replace(/_/g, ' ');
};

const getLeadUser = (doc: ExtendedDocument) => {
    // 1. Try the nested object first (hydrated via funnel)
    // 2. Fallback to searching the project users list by ID
    const user = doc.assignee ||
                 doc.user ||
                 props.project.client?.users?.find((u: User) => u.id === doc.assignee_id);

    if (!user) return null;

    // Calculate initials safely
    const firstInitial = user.first_name?.[0] ?? '';
    const lastInitial = user.last_name?.[0] ?? '';
    const initials = (firstInitial + lastInitial).toUpperCase() || '??';

    return {
        ...user,
        initials
    };
};

const handleCreateNavigation = () => {
    // Navigate to the create page instead of opening a modal
    router.visit(props.projectDocumentsRoutes.create({ project: props.project.id }).url);
};

const handleReprocess = (id: string | number) => {
    aiProgress.value = 5;
    aiStatusMessage.value = "Initializing...";

    // Reprocess call now uses the internal funnel logic
    setDocToProcessing(id);
};

const onDeleteRequested = (doc: any) => {
    isDetailsSheetOpen.value = false;
    selectedSheetId.value = null;
    emit('confirmDelete', doc);
};

const refreshDocumentData = () => {
    router.get(usePage().url, {}, {
        only: ['requirementStatus'],
        preserveState: true,
        preserveScroll: true
    });
};
</script>

<template>
    <div class="space-y-6">
        <transition
            enter-active-class="transition-opacity duration-300"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-500"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="isAiProcessing" class="fixed top-0 left-0 right-0 z-[100] h-[3px] bg-indigo-100/20">
                <div
                    class="h-full bg-indigo-600 transition-all duration-500 ease-out shadow-[0_0_8px_rgba(79,70,229,0.5)]"
                    :style="{ width: `${aiProgress}%` }"
                ></div>
            </div>
        </transition>


        <transition
            enter-active-class="transition duration-500"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
        >
            <Alert v-if="isAiProcessing" class="bg-indigo-50 border-indigo-100 p-0 block shadow-sm overflow-hidden mb-6">
                <div class="p-4 flex justify-between items-center">
                    <div class="flex items-center gap-3">
                        <AppLogoIcon class="h-10 w-10 text-indigo-600" />
                        <div>
                            <AlertTitle class="text-sm font-black text-indigo-900 animate-pulse uppercase tracking-widest">
                                AI Sync Active
                            </AlertTitle>
                            <AlertDescription class="text-xs text-indigo-700/70 font-medium">
                                {{ aiStatusMessage || 'Synchronizing project mapping...' }}
                            </AlertDescription>
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-1 mr-4">
                        <RefreshCw class="animate-spin text-indigo-400 h-5 w-5" />
                    </div>
                </div>
            </Alert>
        </transition>

        <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-white dark:bg-slate-900 p-2 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
            <div class="relative w-full md:w-80 lg:w-96">
                <Search class="absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
                <Input v-model="searchQuery" placeholder="Search documentation..." class="pl-11 bg-slate-50 dark:bg-slate-950 border-none h-11 rounded-xl focus-visible:ring-1 focus-visible:ring-slate-300" />
            </div>
            <div class="flex items-center gap-2 w-full md:w-auto pr-2">
                <Button @click="handleCreateNavigation" class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl px-6 h-11 font-bold">
                    <PlusIcon class="h-4 w-4 mr-2" /> New Intake
                </Button>
            </div>
        </div>

        <div class="hidden md:grid grid-cols-[1fr_120px_100px_120px] gap-4 px-6 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">
            <span>Documentation Hierarchy</span>
            <span class="text-right">Status</span>
            <span class="text-right">Project Lead</span>
            <span class="text-right pr-4">Actions</span>
        </div>

        <div class="grid gap-3">
            <TraceabilityRow
                v-for="intake in documentTree"
                :key="intake.id"
                :item="intake"
                :level="0"
                :active-editing-id="activeEditingId"
                :selected-sheet-id="selectedSheetItem?.id ?? null"
                :expanded-root-ids="expandedRootIds"
                :get-doc-label="getDocLabel"
                :get-lead-user="getLeadUser"
                :requirement-status="props.requirementStatus"
                :users="project.client?.users || []"
                :form="form"
                @toggle-root="toggleRoot"
                @prepare-edit="handlePrepareEdit"
                @handle-reprocess="handleReprocess"
                @on-delete-requested="onDeleteRequested"
                @submit="handleUpdateDocument"
            />
        </div>
    </div>

    <TraceabilityDetailSheet
        v-model:open="isDetailsSheetOpen"
        :item="selectedSheetItem"
        :get-doc-label="getDocLabel"
        :users="project.client?.users || []"
        :requirement-status="props.requirementStatus"
        :active-editing-id="activeEditingId"
        :form="form"
        @prepare-edit="handlePrepareEdit"
        @submit="handleUpdateDocument"
        @task-created="refreshDocumentData"
        @on-delete-requested="onDeleteRequested"
    />
</template>

<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { toast } from 'vue-sonner';
import { Search } from 'lucide-vue-next';
import { useDocumentActions } from '@/composables/useDocumentActions';
import { useProjectState } from '@/composables/useProjectState';
import { useAiProcessing } from '@/composables/useAiProcessing';
import { useWorkflow } from '@/composables/useWorkflow';

// UI Components
import { Input } from '@/components/ui/input';
import TraceabilityRow from './TraceabilityRow.vue';

const props = defineProps<{
    project: Project;
    liveDocuments: ProjectDocument[];
    isGenerating: boolean;
}>();

const emit = defineEmits(['confirmDelete', 'generate']);

// --- 1. ENCAPSULATED STATE ---

/**
 * We define the schema here at the top level so it is reactive
 * and available to both the State composable and our local helpers.
 */
const schema = computed(() => props.project.type.document_schema || []);

const {
    documentsMap,
    allDocs,
    searchQuery,
    expandedRootIds,
    documentTree,
    toggleRoot,
    updateDocument
} = useProjectState(props.project.documents, schema);

const getDocLabel = (typeKey: string) => {
    return schema.value.find((item: any) => item.key === typeKey)?.label || typeKey.replace(/_/g, ' ');
};

// --- 2. ACTION LOGIC ---
const aiStatusMessageRef = ref('');

const {
    form,
    updateDocument: originalUpdateDocument,
    setDocToProcessing,
    targetBeingCreated,
    editingDocumentId
} = useDocumentActions(
    props,
    aiStatusMessageRef,
    updateDocument
);

// --- 3. ENCAPSULATED AI & REAL-TIME ---
const { aiStatusMessage, aiProgress } = useAiProcessing(
    props.project.id,
    allDocs,
    targetBeingCreated,
    (incomingDoc: ExtendedDocument) => {
        updateDocument(incomingDoc.id, incomingDoc);
        if (targetBeingCreated.value === incomingDoc.type) {
            targetBeingCreated.value = null;
        }
    },
    () => {
        toast.success('Processing Complete', { description: 'Document has been successfully analyzed.' });
    },
    (errorMessage) => {
        toast.error('AI Processing Failed', { description: errorMessage });
        targetBeingCreated.value = null;
    }
);

watch(aiStatusMessage, (val) => aiStatusMessageRef.value = val);

// --- 4. UI LOCAL STATE & METHODS ---
const activeEditingId = ref<string | null>(null);
const selectedSheetId = ref<string | null>(null);
const isDetailsSheetOpen = ref(false);

const selectedSheetItem = computed(() => {
    if (!selectedSheetId.value) return null;
    // Map is keyed by UUID string
    return (documentsMap.value.get(selectedSheetId.value) as ExtendedDocument) || null;
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

const getLeadUser = (doc: ExtendedDocument) => {
    const user = doc.assignee ||
                 doc.user ||
                 props.project.client?.users?.find((u: User) => u.id === doc.assignee_id);

    if (!user) return null;

    const firstInitial = user.first_name?.[0] ?? '';
    const lastInitial = user.last_name?.[0] ?? '';
    const initials = (firstInitial + lastInitial).toUpperCase() || '??';

    return { ...user, initials };
};

const handleReprocess = (id: string) => {
    const doc = allDocs.value.find(d => d.id === id) as UIProjectDocument | undefined;
    if (!doc) return;

    aiProgress.value = 5;
    aiStatusMessage.value = "Initializing...";
    void setDocToProcessing(doc);
};

const onDeleteRequested = (doc: any) => {
    isDetailsSheetOpen.value = false;
    selectedSheetId.value = null;
    emit('confirmDelete', doc);
};

// --- 5. WORKFLOW LOGIC ---
const { reprocessableTypes } = useWorkflow(props.project);

</script>

<template>
    <div class="space-y-6">

        <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-white dark:bg-slate-900 p-2 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
            <div class="relative w-full md:w-80 lg:w-96">
                <Search class="absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
                <Input v-model="searchQuery" placeholder="Search documentation..." class="pl-11 bg-slate-50 dark:bg-slate-950 border-none h-11 rounded-xl focus-visible:ring-1 focus-visible:ring-slate-300" />
            </div>
        </div>

        <div class="grid gap-3">
            <TraceabilityRow
                v-for="intake in documentTree"
                :key="intake.id"
                :item="intake"
                :reprocessable-types="reprocessableTypes"
                :level="0"
                :active-editing-id="activeEditingId"
                :selected-sheet-id="selectedSheetItem?.id ?? null"
                :expanded-root-ids="expandedRootIds"
                :get-doc-label="getDocLabel"
                :get-lead-user="getLeadUser"
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


</template>

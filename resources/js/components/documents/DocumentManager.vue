<script setup lang="ts">
import { ref, watch, computed, onMounted, nextTick } from 'vue';
import { toast } from 'vue-sonner';
import { Search } from 'lucide-vue-next';
import { useDocumentActions } from '@/composables/useDocumentActions';
import { useProjectState } from '@/composables/useProjectState';
import { useAiProcessing } from '@/composables/useAiProcessing';
import { useWorkflow } from '@/composables/useWorkflow';
import { FLAT_SEARCH_ICON, FLAT_SEARCH_INPUT } from '@/lib/flat-ui';

// UI Components
import { Input } from '@/components/ui/input';
import TraceabilityRow from './TraceabilityRow.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';

const props = withDefaults(defineProps<{
    project: Project;
    liveDocuments?: ProjectDocument[]; // The '?' makes it optional
    isGenerating: boolean;
}>(), {
    liveDocuments: () => [], // Provides a default empty array
    isGenerating: false
});


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
    updateDocument,
    removeDocuments
} = useProjectState(() => props.liveDocuments, schema);

const getDocLabel = (typeKey: string) => {
    return schema.value.find((item: any) => item.key === typeKey)?.label || typeKey.replace(/_/g, ' ');
};

const isTaskType = (typeKey: string): boolean => {
    return schema.value.find((item: any) => item.key === typeKey)?.is_task ?? false;
};

// --- 2. ACTION LOGIC ---
const aiStatusMessageRef = ref('');

const {
    form,
    updateDocument: originalUpdateDocument,
    setDocToProcessing,
    setDocToTransitioning,
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
    },
    removeDocuments
);

watch(aiStatusMessage, (val) => aiStatusMessageRef.value = val);

// --- 4. UI LOCAL STATE & METHODS ---
const activeEditingId = ref<string | null>(null);
const selectedSheetId = ref<string | null>(null);
const isDetailsSheetOpen = ref(false);
const reprocessConfirmDoc = ref<UIProjectDocument | null>(null);

const selectedSheetItem = computed(() => {
    if (!selectedSheetId.value) return null;
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

    if (!aiProcessedParentIds.value.has(id)) {
        aiProgress.value = 5;
        aiStatusMessage.value = 'Initializing...';
        void setDocToProcessing(doc);
        return;
    }

    reprocessConfirmDoc.value = doc;
};

const executeReprocess = () => {
    const doc = reprocessConfirmDoc.value;
    reprocessConfirmDoc.value = null;
    if (!doc) return;
    aiProgress.value = 5;
    aiStatusMessage.value = 'Initializing...';
    void setDocToProcessing(doc);
};

type TransitionPayload = { toKey?: string; aiTemplateId: number; singleOutput?: boolean; projectTypeId?: string };

const transitionConfirm = ref<{ doc: UIProjectDocument; payload: TransitionPayload } | null>(null);

const handleTransition = (id: string, payload: TransitionPayload) => {
    const doc = allDocs.value.find(d => d.id === id) as UIProjectDocument | undefined;
    if (!doc) return;

    if (!aiProcessedParentIds.value.has(id)) {
        aiProgress.value = 5;
        aiStatusMessage.value = 'Initializing...';
        void setDocToTransitioning(doc, payload);
        return;
    }

    transitionConfirm.value = { doc, payload };
};

const executeTransition = () => {
    const pending = transitionConfirm.value;
    transitionConfirm.value = null;
    if (!pending) return;
    aiProgress.value = 5;
    aiStatusMessage.value = 'Initializing...';
    void setDocToTransitioning(pending.doc, pending.payload);
};

const onDeleteRequested = (doc: any) => {
    isDetailsSheetOpen.value = false;
    selectedSheetId.value = null;
    emit('confirmDelete', doc);
};

// --- 5. WORKFLOW LOGIC ---
const { reprocessableTypes } = useWorkflow();

const aiProcessedParentIds = computed(() => {
    const ids = new Set<string>();
    allDocs.value.forEach(d => { if (d.parent_id) ids.add(d.parent_id); });
    return ids;
});

// --- 6. EXPANDED STATE + SCROLL PERSISTENCE ---
const expandedKey = `doc_expanded_${props.project.id}`;
const scrollKey = `doc_scroll_${props.project.id}`;

// Save expanded IDs to sessionStorage on every change.
watch(expandedRootIds, (newSet) => {
    sessionStorage.setItem(expandedKey, JSON.stringify(Array.from(newSet)));
}, { deep: true });

onMounted(() => {
    // Restore expanded IDs.
    const savedExpanded = sessionStorage.getItem(expandedKey);
    if (savedExpanded) {
        try {
            const ids: string[] = JSON.parse(savedExpanded);
            ids.forEach(id => expandedRootIds.value.add(id));
        } catch {}
    }

    // Restore scroll position (saved by navigateToDetails before leaving).
    const savedScroll = sessionStorage.getItem(scrollKey);
    if (savedScroll !== null) {
        sessionStorage.removeItem(scrollKey);
        const y = parseInt(savedScroll, 10);
        if (y > 0) {
            nextTick(() => window.scrollTo({ top: y, behavior: 'instant' }));
        }
    }
});

</script>

<template>
    <div class="space-y-6">

        <div class="relative w-full md:w-80 lg:w-96 group">
            <Search :class="FLAT_SEARCH_ICON" />
            <Input
                v-model="searchQuery"
                placeholder="Search documentation..."
                :class="FLAT_SEARCH_INPUT"
            />
        </div>

        <div class="grid gap-0.5">
            <TraceabilityRow
                v-for="intake in documentTree"
                :key="intake.id"
                :item="intake"
                :reprocessable-types="reprocessableTypes"
                :ai-processed-parent-ids="aiProcessedParentIds"
                :level="0"
                :active-editing-id="activeEditingId"
                :selected-sheet-id="selectedSheetItem?.id ?? null"
                :expanded-root-ids="expandedRootIds"
                :get-doc-label="getDocLabel"
                :is-task-type="isTaskType"
                :get-lead-user="getLeadUser"
                :users="project.client?.users || []"
                :form="form"
                :is-read-only="project.inactive"
                @toggle-root="toggleRoot"
                @prepare-edit="handlePrepareEdit"
                @handle-reprocess="handleReprocess"
                @handle-transition="handleTransition"
                @on-delete-requested="onDeleteRequested"
                @submit="handleUpdateDocument"
            />
        </div>
    </div>

    <ConfirmDeleteModal
        :open="!!reprocessConfirmDoc"
        title="Reprocess Document?"
        :description="`This will re-run the AI on &quot;${reprocessConfirmDoc?.name}&quot; and overwrite the current content. This action cannot be undone.`"
        confirm-label="Reprocess"
        @close="reprocessConfirmDoc = null"
        @confirm="executeReprocess"
    />

    <ConfirmDeleteModal
        :open="!!transitionConfirm"
        title="Run Transition?"
        :description="`This will replace the current children of &quot;${transitionConfirm?.doc?.name}&quot; with the result of this transition. This action cannot be undone.`"
        confirm-label="Transform"
        @close="transitionConfirm = null"
        @confirm="executeTransition"
    />

</template>

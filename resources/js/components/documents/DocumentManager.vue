<script setup lang="ts">
import { ref, watch, computed, onMounted, nextTick } from 'vue';
import { toast } from 'vue-sonner';
import { usePage } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import { useDocumentActions } from '@/composables/useDocumentActions';
import { useProjectState } from '@/composables/useProjectState';
import { useAiProcessing } from '@/composables/useAiProcessing';
import { useWorkflow, reprocessDescription } from '@/composables/useWorkflow';
import { mergeAssigneeOptions } from '@/lib/assignees';
import { FLAT_SEARCH_ICON, FLAT_SEARCH_INPUT } from '@/lib/flat-ui';

// UI Components
import { Input } from '@/components/ui/input';
import TraceabilityRow from './TraceabilityRow.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ReprocessPromptModal from '@/components/ReprocessPromptModal.vue';

const props = withDefaults(defineProps<{
    project: Project;
    liveDocuments?: ProjectDocument[]; // The '?' makes it optional
    documentTypeCatalog?: DocumentSchemaItem[];
    isGenerating: boolean;
}>(), {
    liveDocuments: () => [], // Provides a default empty array
    documentTypeCatalog: () => [],
    isGenerating: false
});


const emit = defineEmits(['confirmDelete', 'generate']);

// --- 1. ENCAPSULATED STATE ---

/**
 * We define the schema here at the top level so it is reactive
 * and available to both the State composable and our local helpers.
 */
const schema = computed(() => props.documentTypeCatalog);

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
    updateField,
    setDocToProcessing,
    setDocToTransitioning,
    targetBeingCreated,
} = useDocumentActions(
    props,
    aiStatusMessageRef,
    updateDocument
);

// Same merged users+invitations list the document assignee picker itself uses (see
// DocumentSidebar.vue/DocumentContent.vue) — lets a tree row's assignee field offer a pending
// invitee, not just registered users. project.client.organization.{users,invitations} are
// already eager-loaded for this page (see ProjectController::show()).
const assigneeOptions = computed(() =>
    mergeAssigneeOptions(props.project.client?.organization?.users, props.project.client?.organization?.invitations)
);

const page = usePage();
const usesExternalDueDates = computed(() => (page.props as any).orgMembership?.uses_external_due_dates ?? false);

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
const reprocessConfirmDoc = ref<UIProjectDocument | null>(null);

const getLeadUser = (doc: ExtendedDocument) => {
    const user = doc.assignee ||
                 doc.user ||
                 props.project.client?.users?.find((u: User) => u.id === doc.assignee_id);

    if (user) {
        const firstInitial = user.first_name?.[0] ?? '';
        const lastInitial = user.last_name?.[0] ?? '';
        const initials = (firstInitial + lastInitial).toUpperCase() || '??';

        return { ...user, initials };
    }

    // Assigned to someone invited but not yet a real account — show them the same way,
    // just flagged so TraceabilityRow can grey out the avatar (see UserInfo.vue's
    // has_password convention for the same treatment elsewhere).
    const invitation = doc.pending_assignee;
    if (invitation) {
        const firstInitial = invitation.first_name?.[0] ?? '';
        const lastInitial = invitation.last_name?.[0] ?? '';
        const initials = (firstInitial + lastInitial).toUpperCase() || '??';
        const name = [invitation.first_name, invitation.last_name].filter(Boolean).join(' ') || invitation.email;

        return { id: invitation.id, name, initials, isPending: true };
    }

    return null;
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

const executeReprocess = (oneOffInstructions: string | null = null) => {
    const doc = reprocessConfirmDoc.value;
    reprocessConfirmDoc.value = null;
    if (!doc) return;
    aiProgress.value = 5;
    aiStatusMessage.value = 'Initializing...';
    void setDocToProcessing(doc, oneOffInstructions);
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
                v-for="(intake, index) in documentTree"
                :key="intake.id"
                :item="intake"
                :index="index"
                :reprocessable-types="reprocessableTypes"
                :ai-processed-parent-ids="aiProcessedParentIds"
                :level="0"
                :selected-sheet-id="null"
                :expanded-root-ids="expandedRootIds"
                :get-doc-label="getDocLabel"
                :is-task-type="isTaskType"
                :get-lead-user="getLeadUser"
                :assignee-options="assigneeOptions"
                :uses-external-due-dates="usesExternalDueDates"
                :is-read-only="project.inactive"
                :columns="project.kanban_columns ?? []"
                @toggle-root="toggleRoot"
                @handle-reprocess="handleReprocess"
                @handle-transition="handleTransition"
                @on-delete-requested="onDeleteRequested"
                @update-task="(id, field, val) => updateField(String(id), field, val)"
            />
        </div>
    </div>

    <ReprocessPromptModal
        :open="!!reprocessConfirmDoc"
        title="Reprocess Document?"
        :description="reprocessDescription(reprocessConfirmDoc)"
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

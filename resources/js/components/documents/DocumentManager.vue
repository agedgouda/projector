<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useEcho } from '@laravel/echo-vue';
import { useDocumentActions } from '@/composables/useDocumentActions';
import { useDocumentTree } from '@/composables/useDocumentTree';
import { Skeleton } from "@/components/ui/skeleton"
import { globalAiState } from '@/state';
import {
    PlusIcon, Search, RefreshCw, GitGraph,
} from 'lucide-vue-next';

// UI Components
import AppLogoIcon from '@/components/AppLogoIcon.vue'
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
// Removed unused Skeleton import
import { Alert, AlertTitle, AlertDescription } from "@/components/ui/alert";
import DocumentFormModal from './DocumentFormModal.vue';
import TraceabilityRow from './TraceabilityRow.vue';

export type ExtendedDocument = ProjectDocument & {
    currentStatus?: string | null;
    hasError?: boolean;
    processingError?: string | null;
    children?: ExtendedDocument[];
};

const props = defineProps<{
    project: Project;
    requirementStatus: RequirementStatus[];
    canGenerate: boolean;
    isGenerating: boolean;
    projectDocumentsRoutes: any;
}>();

const emit = defineEmits(['confirmDelete', 'generate']);
const aiStatusMessage = ref<string>('');
const documentsMap = ref<Map<string | number, ExtendedDocument>>(new Map());

// Tracking active inline editor
const activeEditingId = ref<string | number | null>(null);

// 1. Sync Logic
const syncFromProps = (statusGroups: RequirementStatus[]) => {
    const freshMap = new Map<string | number, ExtendedDocument>();
    statusGroups.forEach(group => {
        group.documents.forEach(doc => {
            const existing = documentsMap.value.get(doc.id);
            freshMap.set(doc.id, {
                ...doc,
                currentStatus: existing?.currentStatus || null,
                hasError: existing?.hasError || false,
                processingError: existing?.processingError || null
            });
        });
    });
    documentsMap.value = freshMap;
};

syncFromProps(props.requirementStatus);
watch(() => props.requirementStatus, (newVal) => syncFromProps(newVal), { deep: true });

const allDocs = computed(() => Array.from(documentsMap.value.values()));

const localRequirements = computed(() => {
    return props.requirementStatus.map(group => ({
        ...group,
        documents: allDocs.value.filter(d => d.type === group.key)
    }));
});

// Extract editingDocumentId from composable to ensure URL builder has access to it
const {
    form,
    isUploadModalOpen,
    openUploadModal,
    submitDocument,
    updateDocument: originalUpdateDocument,
    setDocToProcessing,
    targetBeingCreated,
    editingDocumentId // This will no longer be undefined
} = useDocumentActions(props, localRequirements, aiStatusMessage);

// Handle Inline Editing State
const handlePrepareEdit = (item: any) => {
    if (!item || item.id === null) {
        activeEditingId.value = null;
        if (editingDocumentId) editingDocumentId.value = null;
        form.reset();
        return;
    }

    activeEditingId.value = item.id;

    // This is the fix for your "reading id of null" error
    // It populates the ref that the URL generator uses
    if (editingDocumentId) {
        editingDocumentId.value = item.id;
    }

    // Standard form population
    form.name = item.name;
    form.content = item.content;
    form.type = item.type;
    form.assignee_id = item.assignee_id;
};

const handleUpdateDocument = (callbackFromRow?: any) => {
    originalUpdateDocument(() => {
        activeEditingId.value = null;
        if (typeof callbackFromRow === 'function') {
            callbackFromRow();
        }
    });
};

const { searchQuery, expandedRootIds, documentTree, toggleRoot } = useDocumentTree(allDocs);

// AI State and Echo Logic
const isAiProcessing = computed(() => !!targetBeingCreated.value || allDocs.value.some(d => d.processed_at === null));
watch(isAiProcessing, (newVal) => { globalAiState.value.isProcessing = newVal; }, { immediate: true });

useEcho(`project.${props.project.id}`, ['.document.vectorized', '.DocumentProcessingUpdate'], (payload: any) => {
    const docId = payload.document_id || payload.document?.id;
    if (!docId) return;

    const doc = documentsMap.value.get(docId);

    if (payload.statusMessage) {
        const msg = payload.statusMessage.toLowerCase();
        if (msg.includes('error') && doc) doc.processingError = payload.statusMessage;
        if (msg.includes('success')) {
            targetBeingCreated.value = null;
            if (doc) doc.processingError = null;
        }
    }

    if (payload.document) {
        const existingError = documentsMap.value.get(docId)?.processingError;
        documentsMap.value.set(docId, {
            ...payload.document,
            processingError: existingError || null
        });

        let currentParentId = payload.document.parent_id;
        while (currentParentId) {
            expandedRootIds.value.add(currentParentId);
            const parentDoc = documentsMap.value.get(currentParentId);
            currentParentId = parentDoc?.parent_id;
        }
    }
}, [props.project.id], 'private');

const getDocLabel = (typeKey: string) => {
    const schema = props.project.type.document_schema || [];
    return schema.find((item: any) => item.key === typeKey)?.label || typeKey.replace(/_/g, ' ');
};

const getLeadUser = (doc: any) => {
    // Fixed 'let' to 'const' for user variable
    const user = doc.assignee || doc.user || props.project.client?.users?.find((u: any) => u.id === doc.assigned_id);

    if (!user) return null;
    return { ...user, initials: `${user.first_name?.[0] || ''}${user.last_name?.[0] || ''}`.toUpperCase() || '??' };
};

const handleReprocess = (id: string | number) => {
    const doc = documentsMap.value.get(id);
    if (doc) doc.processingError = null;
    setDocToProcessing(id);
};

const onDeleteRequested = (doc: ExtendedDocument) => emit('confirmDelete', doc);
</script>

<template>
    <div class="max-w-7xl mx-auto p-6 space-y-6">
        <transition enter-active-class="transition duration-500" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0">
            <Alert v-if="isAiProcessing" class="bg-indigo-50 border-indigo-100 p-0 block shadow-sm overflow-hidden">
                <div class="p-4 flex justify-between items-center">
                    <div class="flex items-center gap-3">
                        <AppLogoIcon class="h-10 w-10 text-indigo-600" />
                        <div>
                            <AlertTitle class="text-sm font-black text-indigo-900 animate-pulse uppercase tracking-widest">AI Sync Active</AlertTitle>
                            <AlertDescription class="text-xs text-indigo-700/70 font-medium">{{ aiStatusMessage || 'Synchronizing project mapping...' }}</AlertDescription>
                        </div>
                    </div>
                    <RefreshCw class="animate-spin text-indigo-400 h-5 w-5 mr-4" />
                </div>
                <Skeleton class="h-[3px] w-full bg-indigo-200 rounded-none" />
            </Alert>
        </transition>

        <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="p-8 flex justify-between items-center border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 bg-indigo-50 rounded-xl text-indigo-600"><GitGraph class="h-6 w-6"/></div>
                    <h2 class="text-2xl font-black text-slate-900 tracking-tight">Traceability Map</h2>
                </div>
                <Button @click="openUploadModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl px-6 h-11">
                    <PlusIcon class="h-4 w-4 mr-2" /> New Intake
                </Button>
            </div>

            <div class="px-8 pt-6">
                <div class="relative">
                    <Search class="absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
                    <Input v-model="searchQuery" placeholder="Search documentation..." class="pl-12 h-12 bg-slate-50 border-slate-100 rounded-xl" />
                </div>
            </div>

            <div class="grid grid-cols-12 px-10 py-5 mt-6 text-[10px] font-bold uppercase tracking-widest text-slate-400 bg-slate-50/30 border-b border-slate-50">
                <div class="col-span-8">Documentation Hierarchy</div>
                <div class="col-span-2 text-center border-x border-slate-100/50">Project Lead</div>
                <div class="col-span-2 text-center">Actions</div>
            </div>

            <div class="divide-y divide-slate-50">
                <TraceabilityRow
                    v-for="intake in documentTree"
                    :key="intake.id"
                    :item="intake"
                    :level="0"
                    :active-editing-id="activeEditingId"
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
    </div>
    <DocumentFormModal v-model:open="isUploadModalOpen" mode="create" :form="form" :requirement-status="props.requirementStatus" :users="project.client?.users || []" @submit="submitDocument" />
</template>

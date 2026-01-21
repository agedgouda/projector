<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { useDocumentActions } from '@/composables/useDocumentActions';
import { useDocumentTree } from '@/composables/useDocumentTree';
import { globalAiState } from '@/state';
import {
    PlusIcon, Search, RefreshCw,
} from 'lucide-vue-next';

// UI Components
import AppLogoIcon from '@/components/AppLogoIcon.vue'
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { toast } from 'vue-sonner';
import { Alert, AlertTitle, AlertDescription } from "@/components/ui/alert";
import DocumentFormModal from './DocumentFormModal.vue';
import TraceabilityRow from './TraceabilityRow.vue';
import TraceabilityDetailSheet from './TraceabilityDetailSheet.vue';

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
const aiProgress = ref<number>(0);
const documentsMap = ref<Map<string | number, ExtendedDocument>>(new Map());

// Tracking active inline editor
const activeEditingId = ref<string | number | null>(null);

// Details Sheet State
const selectedSheetItem = ref<ExtendedDocument | null>(null);
const isDetailsSheetOpen = ref(false);

const handleOpenSheet = (item: ExtendedDocument) => {
    selectedSheetItem.value = item;
    isDetailsSheetOpen.value = true;
};

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

const {
    form,
    isUploadModalOpen,
    openUploadModal,
    submitDocument,
    updateDocument: originalUpdateDocument,
    setDocToProcessing,
    targetBeingCreated,
    editingDocumentId
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
    if (editingDocumentId) {
        editingDocumentId.value = item.id;
    }
    form.name = item.name;
    form.content = item.content;
    form.metadata = item.metadata;
    form.type = item.type;
    form.assignee_id = item.assignee_id;
};

const handleUpdateDocument = (callbackFromRow?: any) => {
    originalUpdateDocument(() => {
        // Sync local object state so the form is fresh on next open
        if (selectedSheetItem.value && activeEditingId.value === selectedSheetItem.value.id) {
            selectedSheetItem.value.content = form.content;
            selectedSheetItem.value.name = form.name;
            selectedSheetItem.value.metadata = JSON.parse(JSON.stringify(form.metadata));
            selectedSheetItem.value.assignee_id = form.assignee_id;
        }
        activeEditingId.value = null;
        if (typeof callbackFromRow === 'function') {
            callbackFromRow();
        }
    });
};

const { searchQuery, expandedRootIds, documentTree, toggleRoot } = useDocumentTree(allDocs);

// AI State logic
const isAiProcessing = computed(() => !!targetBeingCreated.value || allDocs.value.some(d => d.processed_at === null));
watch(isAiProcessing, (newVal) => {
    globalAiState.value.isProcessing = newVal;
    if (!newVal) {
        aiProgress.value = 0;
        aiStatusMessage.value = '';
    }
}, { immediate: true });

// Full Echo Logic
useEcho(`project.${props.project.id}`, ['.document.vectorized', '.DocumentProcessingUpdate'], (payload: any) => {
    const docId = payload.document_id || payload.document?.id;
    if (!docId) return;

    const doc = documentsMap.value.get(docId);

    // Update Progress and Message
    if (payload.statusMessage) {
        const message = String(payload.statusMessage);
        const msg = message.toLowerCase();
        const newProgress = Number(payload.progress || 0);

        // 1. Success Detection (Prioritize this)
        const isSuccess = msg.includes('success') || newProgress === 100;
        const isError = msg.includes('error') || msg.includes('failed') || msg.includes('exhausted');

        if (isSuccess && !isError) {
            // Trigger success immediately
            toast.success('Processing Complete', {
                description: 'Document has been successfully analyzed.',
            });

            aiProgress.value = 100;
            targetBeingCreated.value = null;
            if (doc) doc.processingError = null;
        }
        // 2. Error Handling
        else if (isError) {
            if (doc) doc.processingError = message;
            toast.error('AI Processing Failed', {
                description: message,
            });
        }

        // 3. Update Progress (Only if not already finished)
        if (!isSuccess && newProgress > aiProgress.value) {
            aiProgress.value = newProgress;
        }

        aiStatusMessage.value = message;
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
    const user = doc.assignee || doc.user || props.project.client?.users?.find((u: any) => u.id === doc.assigned_id);
    if (!user) return null;
    return { ...user, initials: `${user.first_name?.[0] || ''}${user.last_name?.[0] || ''}`.toUpperCase() || '??' };
};

const handleReprocess = (id: string | number) => {
    // 1. Instant UI Feedback
    aiProgress.value = 5;
    aiStatusMessage.value = "Initializing...";

    const doc = documentsMap.value.get(id);
    if (doc) doc.processingError = null;
    setDocToProcessing(id);
};

const onDeleteRequested = (doc: ExtendedDocument) => {
    isDetailsSheetOpen.value = false;
    selectedSheetItem.value = null;
    emit('confirmDelete', doc);
};

const refreshDocumentData = () => {
    router.get(usePage().url, {}, {
        only: ['requirementStatus'],
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            if (selectedSheetItem.value) {
                const freshDoc = documentsMap.value.get(selectedSheetItem.value.id);
                if (freshDoc) {
                    selectedSheetItem.value = freshDoc;
                }
            }
        }
    });
};

let creepInterval: any = null;
watch(aiProgress, (newVal) => {
    // Clear existing creep whenever a real update arrives
    if (creepInterval) clearInterval(creepInterval);

    // If we are at the "Analyzing" or "Generating" steps, start a slow creep
    if (newVal > 0 && newVal < 90) {
        creepInterval = setInterval(() => {
            if (aiProgress.value < newVal + 15) { // Only creep up to 15% past the milestone
                aiProgress.value += 0.5;
            }
        }, 1000); // Move a tiny bit every second
    }
});
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
                <Button @click="openUploadModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl px-6 h-11 font-bold">
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
                @open-sheet="handleOpenSheet"
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

    <DocumentFormModal v-model:open="isUploadModalOpen" mode="create" :form="form" :requirement-status="props.requirementStatus" :users="project.client?.users || []" @submit="submitDocument" />
</template>

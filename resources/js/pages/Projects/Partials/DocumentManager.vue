<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { toast } from 'vue-sonner';
import { useEcho } from '@laravel/echo-vue';
import { useDocumentActions } from '@/composables/useDocumentActions';

// UI Imports
import { PlusIcon, Search, RefreshCw } from 'lucide-vue-next';
import AppLogoIcon from '@/components/AppLogoIcon.vue'
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from "@/components/ui/skeleton"
import { Alert, AlertTitle, AlertDescription } from "@/components/ui/alert";
import DocumentRequirementSection from './DocumentRequirementSection.vue';
import DocumentFormModal from './DocumentFormModal.vue';

// --- 1. PROPS & EMITS ---
const props = defineProps<{
    project: any;
    requirementStatus: RequirementStatus[];
    canGenerate: boolean;
    isGenerating: boolean;
    projectDocumentsRoutes: any;
}>();

const emit = defineEmits(['confirmDelete', 'generate']);
const aiStatusMessage = ref<string>('');

// --- 2. LOCAL UI STATE ---
const localRequirements = ref<RequirementStatus[]>([...props.requirementStatus]);
const searchQuery = ref('');
const expandedDocId = ref<string | number | null>(null);
const selectedTypes = ref<string[]>(props.requirementStatus.map(r => r.key));

// --- 3. COMPOSABLE (Action Logic) ---
const {
    form,
    isUploadModalOpen,
    isEditModalOpen,
    openUploadModal,
    openEditModal,
    submitDocument,
    updateDocument,
    setDocToProcessing,
    targetBeingCreated // This is the new ref from useDocumentActions
} = useDocumentActions(props, localRequirements, aiStatusMessage);

// --- 4. COMPUTED ---

/**
 * Determines which section should show the "Drafting..." shimmer.
 * Priority 1: A brand new document being created (Ghost Target).
 * Priority 2: An existing document being reprocessed (Workflow Target).
 */
const activeTargetType = computed(() => {
    // Check for Ghost Target (New Uploads)
    if (targetBeingCreated.value) return targetBeingCreated.value;

    // Check for Reprocessing Targets (Existing Docs)
    const processingDoc = localRequirements.value
        .flatMap(group => group.documents)
        .find(doc => doc.processed_at === null);

    if (!processingDoc || !props.project?.type?.workflow) return null;

    const workflow = props.project.type.workflow;
    const activeStep = workflow.find((step: any) => step.from_key === processingDoc.type);

    return activeStep ? activeStep.to_key : null;
});

/**
 * Controls the visibility of the Top Alert Banner.
 */
const isAiProcessing = computed(() => {
    // Show banner if we are waiting for a ghost target
    if (targetBeingCreated.value) return true;

    // Show banner if any existing document is in processing state
    return localRequirements.value.some(group =>
        group.documents.some(doc => doc.processed_at === null)
    );
});

/**
 * Filtered requirements based on search and type toggles.
 */
const filteredRequirements = computed(() => {
    const query = searchQuery.value.toLowerCase();
    return localRequirements.value
        .filter(req => selectedTypes.value.includes(req.key))
        .map(req => ({
            ...req,
            documents: req.documents.filter((doc) =>
                doc.name.toLowerCase().includes(query) ||
                (doc.content && doc.content.toLowerCase().includes(query))
            )
        }))
        .filter(req => req.documents.length > 0 || (query === '' && selectedTypes.value.includes(req.key)));
});

// --- 5. WATCHERS & REAL-TIME (ECHO) ---
watch(() => props.requirementStatus, (newVal) => {
    localRequirements.value = JSON.parse(JSON.stringify(newVal));
}, { deep: true, immediate: true });

useEcho(
    `project.${props.project.id}`,
    [
        '.document.vectorized',
        '.DocumentProcessingUpdate',
        '.App\\Events\\DocumentProcessingUpdate'
    ],
    (payload: any) => {
        // Handle Step-by-Step progress updates
        if (payload.statusMessage) {
            aiStatusMessage.value = payload.statusMessage;
        }

        // Handle Final Document Completion
        else if (payload.document) {
            // If this doc matches the type we were waiting for, clear the "Ghost" state
            if (payload.document.type === targetBeingCreated.value) {
                targetBeingCreated.value = null;
            }

            aiStatusMessage.value = ''; // Reset the status banner

            const updatedDoc = payload.document;
            localRequirements.value = localRequirements.value.map(group => {
                if (group.key !== updatedDoc.type) return group;

                const newDocs = [...group.documents];
                const index = newDocs.findIndex(d => d.id === updatedDoc.id);

                if (index !== -1) {
                    newDocs[index] = updatedDoc;
                } else {
                    newDocs.unshift(updatedDoc);
                }

                return { ...group, documents: newDocs };
            });

            localRequirements.value = [...localRequirements.value];
            toast.success('Project Updated');
        }
    },
    [props.project.id],
    'private'
);

// --- 6. UI-ONLY METHODS ---
const toggleType = (key: string) => {
    const index = selectedTypes.value.indexOf(key);
    if (index > -1) selectedTypes.value.splice(index, 1);
    else selectedTypes.value.push(key);
};

const toggleExpand = (id: string | number) => {
    expandedDocId.value = expandedDocId.value === id ? null : id;
};

const handleDocReprocessing = (id: string) => {
    setDocToProcessing(id);
};

</script>

<template>
    <transition
        enter-active-class="transition duration-500 ease-out"
        enter-from-class="opacity-0 -translate-y-2"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition duration-300 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <Alert
            v-if="isAiProcessing"
            class="mb-6 border-indigo-200 bg-indigo-50/50 overflow-hidden p-0 block"
        >
            <div class="relative w-full p-4 flex items-center justify-between">

                <div class="flex items-center gap-4">
                    <div class="shrink-0">
                        <AppLogoIcon class="h-10 w-10 text-indigo-600" />
                    </div>

                    <div class="flex flex-col">
                        <AlertTitle class="text-sm font-bold text-indigo-900 m-0 leading-tight animate-pulse">
                            AI Pipeline Active
                        </AlertTitle>
                        <AlertDescription class="text-xs text-indigo-700/70 m-0 leading-normal">

                                {{ aiStatusMessage }}

                        </AlertDescription>
                    </div>
                </div>

                <div class="shrink-0">
                    <div class="bg-indigo-600 text-[10px] font-black text-white px-2.5 py-1 rounded-md uppercase tracking-widest shadow-sm animate-pulse">
                        In Progress
                    </div>
                </div>
            </div>

            <Skeleton class="h-[3px] w-full rounded-none bg-indigo-200" />
        </Alert>
    </transition>

    <div class="bg-white p-6 rounded-xl mt-8 border border-gray-200 shadow-sm relative">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <h3 class="text-lg font-bold text-gray-900">Project Documents</h3>
                <transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 -translate-x-2" enter-to-class="opacity-100 translate-x-0" leave-active-class="transition ease-in duration-150" leave-from-class="opacity-100 translate-x-0" leave-to-class="opacity-0 -translate-x-2">
                    <div v-if="form.processing" class="flex items-center gap-1.5 px-2 py-1 rounded-full bg-indigo-50 border border-indigo-100">
                        <RefreshCw class="w-3 h-3 text-indigo-600 animate-spin" />
                        <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-600">Syncing</span>
                    </div>
                </transition>
            </div>
            <Button @click="openUploadModal()" size="sm" class="gap-2 bg-gray-900 text-white hover:bg-gray-800 shadow-sm">
                <PlusIcon class="w-4 h-4" /> Add Document
            </Button>
        </div>

        <div class="bg-slate-50/80 p-3 rounded-lg border border-slate-200/60 mb-6 space-y-3">
            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="req in requirementStatus"
                    :key="req.key"
                    @click="toggleType(req.key)"
                    :class="['px-2.5 py-1 rounded-md border text-[10px] font-bold uppercase tracking-tight transition-all flex items-center gap-2', selectedTypes.includes(req.key) ? 'bg-white border-slate-300 text-slate-900 shadow-sm' : 'bg-transparent border-transparent text-slate-400 hover:text-slate-600']"
                >
                    <div :class="['w-1.5 h-1.5 rounded-full', selectedTypes.includes(req.key) ? 'bg-indigo-500' : 'bg-slate-300']"></div>
                    {{ req.label }}
                </button>

            </div>
            <div class="relative">
                <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400" />
                <Input v-model="searchQuery" type="text" placeholder="Search documents..." class="pl-9 bg-white" />
            </div>
        </div>

        <div class="space-y-1 min-h-[100px]">
            <DocumentRequirementSection
                v-for="req in filteredRequirements"
                :key="req.key"
                :req="req"
                :expanded-doc-id="expandedDocId"
                :is-ai-processing="isAiProcessing"
                :is-target="activeTargetType === req.key"
                @open-upload="openUploadModal"
                @open-edit="openEditModal"
                @toggle-expand="toggleExpand"
                @confirm-delete="(doc) => emit('confirmDelete', doc)"
                @reprocessing="handleDocReprocessing"
            />
            <div v-if="filteredRequirements.length === 0" class="py-12 text-center border-2 border-dashed border-slate-100 rounded-xl bg-slate-50/30">
                <p class="text-sm text-slate-400 italic">No documents match your selection.</p>
            </div>
        </div>
    </div>

    <DocumentFormModal
        v-model:open="isUploadModalOpen"
        mode="create"
        :form="form"
        :requirement-status="requirementStatus"
        @submit="submitDocument"
    />

    <DocumentFormModal
        v-model:open="isEditModalOpen"
        mode="edit"
        :form="form"
        :requirement-status="requirementStatus"
        @submit="updateDocument"
    />
</template>

<style scoped>
/* Target the logo-wheel class inside the AppLogoIcon */
:deep(.logo-wheel) {
    /* This tells the browser to use the center of the path itself */
    transform-origin: center;
    transform-box: fill-box;
    animation: logo-spin 3s linear infinite;
}

/* Optional: Make the left and right wheels spin at slightly different speeds
   or directions to make it look more like a "machine" */
:deep(.left-wheel) {
    animation-duration: 4s;
}

:deep(.right-wheel) {
    animation-direction: reverse;
    animation-duration: 3s;
}

@keyframes logo-spin {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}
</style>

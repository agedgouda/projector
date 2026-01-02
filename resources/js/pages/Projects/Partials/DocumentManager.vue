<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { toast } from 'vue-sonner';
import { useEcho } from '@laravel/echo-vue';
import { useDocumentActions } from '@/composables/useDocumentActions';

// UI Imports
import { PlusIcon, Sparkles, Search, Loader2, RefreshCw } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    setDocToProcessing
} = useDocumentActions(props, localRequirements);


// --- 4. WATCHERS & REAL-TIME (ECHO) ---
watch(() => props.requirementStatus, (newVal) => {
    // We use a deep clone to ensure Vue detects the nested 'processed_at' changes
    localRequirements.value = JSON.parse(JSON.stringify(newVal));
}, { deep: true, immediate: true });

useEcho(
    `project.${props.project.id}`,
    '.document.vectorized',
    (payload: DocumentVectorizedEvent) => {
        const updatedDoc = payload.document;
        localRequirements.value = localRequirements.value.map(group => {
            // We only care about the group this document belongs to
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

        // Ensure the computed isAiProcessing property sees the change
        localRequirements.value = [...localRequirements.value];
        toast.success('Project Updated');
    },
    [props.project.id],
    'private'
);

// --- 5. COMPUTED ---
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

const isAiProcessing = computed(() => {
    const intakeGroup = localRequirements.value.find(req => req.key === 'intake');
    if (!intakeGroup) return false;

    return intakeGroup.documents.some(doc =>
        doc.parent_id === null && doc.processed_at === null
    );
});

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
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="transform -translate-y-4 opacity-0"
        enter-to-class="transform translate-y-0 opacity-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div v-if="isAiProcessing" class="mb-6 p-4 bg-indigo-50 border border-indigo-100 rounded-xl flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="relative">
                    <div class="absolute inset-0 bg-indigo-400 rounded-full animate-ping opacity-25"></div>
                    <Loader2 class="w-5 h-5 text-indigo-600 animate-spin relative" />
                </div>
                <div>
                    <p class="text-sm font-bold text-indigo-900">AI Pipeline Active</p>
                    <p class="text-xs text-indigo-600/80">Gemini is analyzing requirements and generating deliverables...</p>
                </div>
            </div>
            <div class="px-2 py-1 bg-indigo-600 text-[10px] font-bold text-white rounded uppercase tracking-wider animate-pulse">
                In Progress
            </div>
        </div>
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

        <div class="mt-6 pt-6 border-t border-gray-100">
            <Button @click="emit('generate')" class="w-full py-6 font-bold uppercase tracking-widest text-xs shadow-sm bg-indigo-600 hover:bg-indigo-700" :disabled="!canGenerate || isGenerating">
                <Sparkles v-if="!isGenerating" class="w-4 h-4 mr-2" />
                <Loader2 v-else class="w-4 h-4 mr-2 animate-spin" />
                {{ isGenerating ? 'Processing AI...' : (canGenerate ? 'Generate Deliverables' : 'Upload Required Specs to Start') }}
            </Button>
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

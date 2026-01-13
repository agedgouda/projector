<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { toast } from 'vue-sonner';
import { useEcho } from '@laravel/echo-vue';
import { useDocumentActions } from '@/composables/useDocumentActions';

// UI Components
import {
    PlusIcon,
    Search,
    RefreshCw,
    GitGraph,
    AlertCircle
} from 'lucide-vue-next';
import AppLogoIcon from '@/components/AppLogoIcon.vue'
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from "@/components/ui/skeleton"
import { Alert, AlertTitle, AlertDescription } from "@/components/ui/alert";
import DocumentItem from './DocumentItem.vue';
import DocumentFormModal from './DocumentFormModal.vue';
import { globalAiState } from '@/state'

const props = defineProps<{
    project: Project;
    requirementStatus: RequirementStatus[];
    canGenerate: boolean;
    isGenerating: boolean;
    projectDocumentsRoutes: any;
}>();

const emit = defineEmits(['confirmDelete', 'generate']);
const aiStatusMessage = ref<string>('');

// localRequirements uses the Global RequirementStatus type from your types file
const localRequirements = ref<RequirementStatus[]>([...props.requirementStatus]);
const searchQuery = ref('');
const expandedDocId = ref<string | number | null>(null);

// --- COMPOSABLE LOGIC ---
const {
    form,
    isUploadModalOpen,
    isEditModalOpen,
    openUploadModal,
    openEditModal,
    submitDocument,
    updateDocument,
    setDocToProcessing,
    targetBeingCreated
} = useDocumentActions(props, localRequirements, aiStatusMessage);

// --- HIERARCHICAL ENGINE ---
const documentTree = computed(() => {
    const allDocs = localRequirements.value.flatMap(r => r.documents);
    const query = searchQuery.value.toLowerCase();

    // 1. Root Intakes
    const intakes = allDocs.filter(d => d.type === 'intake');

    // 2. Map Stories and Tech Specs based on parent_id relationships
    return intakes.map(intake => {
        const stories = allDocs.filter(d => d.type === 'user_story' && d.parent_id === intake.id);

        return {
            ...intake,
            childrenDocs: stories.map(story => ({
                ...story,
                childrenDocs: allDocs.filter(d =>
                    (d.type === 'functional_requirement' || d.type === 'technical_spec' || d.type === 'technical_task') &&
                    d.parent_id === story.id
                )
            }))
        };
    }).filter(tree => {
        if (!query) return true;
        const matchesQuery = (doc: any): boolean => {
            return doc.name.toLowerCase().includes(query) ||
                   (doc.childrenDocs && doc.childrenDocs.some((c: any) => matchesQuery(c)));
        };
        return matchesQuery(tree);
    });
});

// --- AI STATUS LOGIC ---
const isAiProcessing = computed(() => {
    if (targetBeingCreated.value) return true;
    return localRequirements.value.some(g => g.documents.some(d => d.processed_at === null));
});

const activeTargetType = computed(() => {
    if (targetBeingCreated.value) return targetBeingCreated.value;
    const processingDoc = localRequirements.value.flatMap(g => g.documents).find(d => d.processed_at === null);
    if (!processingDoc || !props.project?.type?.workflow) return null;
    const activeStep = props.project.type.workflow.find((s: any) => s.from_key === processingDoc.type);
    return activeStep ? activeStep.to_key : null;
});

// --- REAL-TIME ECHO ---
useEcho(
    `project.${props.project.id}`,
    ['.document.vectorized', '.DocumentProcessingUpdate', '.App\\Events\\DocumentProcessingUpdate'],
    (payload: any) => {
        if (payload.statusMessage) {
            aiStatusMessage.value = payload.statusMessage;
        } else if (payload.document) {
            if (payload.document.type === targetBeingCreated.value) {
                targetBeingCreated.value = null;
            }
            aiStatusMessage.value = '';
            const incomingDoc: ProjectDocument = payload.document;

            localRequirements.value = localRequirements.value.map(group => {
                if (group.key !== incomingDoc.type) return group;
                const newDocs = [...group.documents];
                const index = newDocs.findIndex(d => d.id === incomingDoc.id);
                if (index !== -1) {
                    newDocs[index] = incomingDoc;
                } else {
                    newDocs.unshift(incomingDoc);
                }
                return { ...group, documents: newDocs };
            });
            toast.success('Project Updated');
        }
    },
    [props.project.id],
    'private'
);

// --- METHODS ---
const handleDocReprocessing = (id: string | number) => setDocToProcessing(id);
const toggleExpand = (id: string | number) => { expandedDocId.value = expandedDocId.value === id ? null : id; };

watch(isAiProcessing, (newVal) => { globalAiState.value.isProcessing = newVal; }, { immediate: true });
watch(() => props.requirementStatus, (newVal) => { localRequirements.value = JSON.parse(JSON.stringify(newVal)); }, { deep: true, immediate: true });
</script>

<template>
    <transition enter-active-class="transition duration-500" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0">
        <Alert v-if="isAiProcessing" class="mb-6 border-indigo-200 bg-indigo-50/50 p-0 block shadow-sm overflow-hidden">
            <div class="p-4 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <AppLogoIcon class="h-10 w-10 text-indigo-600" />
                    <div class="flex flex-col">
                        <AlertTitle class="text-sm font-bold text-indigo-900 animate-pulse">Logic Circuits Engaged</AlertTitle>
                        <AlertDescription class="text-xs text-indigo-700/70">{{ aiStatusMessage || 'Synchronizing project data...' }}</AlertDescription>
                    </div>
                </div>
                <RefreshCw class="w-5 h-5 text-indigo-400 animate-spin mr-4" />
            </div>
            <Skeleton class="h-[3px] w-full bg-indigo-200 rounded-none" />
        </Alert>
    </transition>

    <div class="bg-white p-8 rounded-3xl border border-slate-200 shadow-sm relative">
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center gap-3">
                <GitGraph class="w-6 h-6 text-indigo-600" />
                <h3 class="text-2xl font-black text-slate-900 tracking-tight">Traceability Map</h3>
            </div>
            <Button @click="openUploadModal()" class="bg-indigo-600 text-white hover:bg-indigo-700 rounded-xl px-6">
                <PlusIcon class="w-4 h-4 mr-2" /> New Intake
            </Button>
        </div>

        <div class="relative mb-10">
            <Search class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
            <Input v-model="searchQuery" placeholder="Search the document tree..." class="pl-12 h-12 bg-slate-50 border-slate-100 rounded-xl focus:ring-2 focus:ring-indigo-100" />
        </div>

        <div class="space-y-8">
            <div v-for="intake in documentTree" :key="intake.id" class="relative">

                <div class="relative z-10">
                    <DocumentItem
                        :doc="(intake as ProjectDocument)"
                        :is-expanded="expandedDocId === intake.id"
                        @toggle="toggleExpand"
                        @edit="openEditModal"
                        @delete="(doc) => emit('confirmDelete', doc)"
                        @reprocessing="handleDocReprocessing"
                    />
                </div>

                <div v-if="(intake.childrenDocs && intake.childrenDocs.length > 0) || (activeTargetType === 'user_story' && intake.processed_at === null)"
                    class="mt-4 ml-6 pl-8 border-l-2 border-slate-100 space-y-4 relative">

                    <div v-for="story in intake.childrenDocs" :key="story.id" class="relative">
                        <div class="absolute -left-[35px] top-6 h-2 w-2 rounded-full bg-slate-200 border-2 border-white"></div>
                        <DocumentItem
                            :doc="(story as ProjectDocument)"
                            :is-expanded="expandedDocId === story.id"
                            @toggle="toggleExpand"
                            @edit="openEditModal"
                            @delete="(doc) => emit('confirmDelete', doc)"
                            @reprocessing="handleDocReprocessing"
                        />

                        <div v-if="story.childrenDocs && story.childrenDocs.length > 0" class="mt-4 ml-6 pl-8 border-l-2 border-indigo-50 space-y-3 relative">
                            <div v-for="spec in story.childrenDocs" :key="spec.id" class="relative">
                                <div class="absolute -left-[35px] top-6 h-1.5 w-1.5 rounded-full bg-indigo-100 border border-white"></div>
                                <DocumentItem
                                    :doc="(spec as ProjectDocument)"
                                    :is-expanded="expandedDocId === spec.id"
                                    @toggle="toggleExpand"
                                    @edit="openEditModal"
                                    @delete="(doc) => emit('confirmDelete', doc)"
                                    @reprocessing="handleDocReprocessing"
                                />
                            </div>
                        </div>
                    </div>

                    <div v-if="activeTargetType === 'user_story' && intake.processed_at === null"
                         class="relative ml-2 p-4 border border-dashed border-indigo-200 rounded-xl bg-indigo-50/30 animate-pulse">
                        <div class="flex items-center gap-3">
                            <RefreshCw class="w-3 h-3 text-indigo-400 animate-spin" />
                            <span class="text-[10px] font-bold text-indigo-400 uppercase tracking-widest italic">AI is drafting Stories...</span>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="documentTree.length === 0" class="py-20 text-center border-2 border-dashed border-slate-100 rounded-3xl bg-slate-50/30">
                <AlertCircle class="w-10 h-10 text-slate-200 mx-auto mb-4" />
                <p class="text-sm text-slate-400 font-medium">No document hierarchies found.</p>
            </div>
        </div>
    </div>

    <DocumentFormModal
        v-model:open="isUploadModalOpen"
        mode="create"
        :form="form"
        :requirement-status="props.requirementStatus"
        :users="project.client?.users || []"
        @submit="submitDocument"
    />
    <DocumentFormModal
        v-model:open="isEditModalOpen"
        mode="edit"
        :form="form"
        :requirement-status="props.requirementStatus"
        :users="project.client?.users || []"
        @submit="updateDocument"
    />
</template>

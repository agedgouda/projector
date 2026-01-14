<script setup lang="ts">
import { ref, computed, watch } from 'vue';
//import { toast } from 'vue-sonner';
import { useEcho } from '@laravel/echo-vue';
import { useDocumentActions } from '@/composables/useDocumentActions';
import {
    PlusIcon, Search, RefreshCw, GitGraph, ChevronRight,
    Edit2, Trash2, RotateCw, FileText
} from 'lucide-vue-next';

import AppLogoIcon from '@/components/AppLogoIcon.vue'
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from "@/components/ui/skeleton"
import { Alert, AlertTitle, AlertDescription } from "@/components/ui/alert";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import DocumentFormModal from './DocumentFormModal.vue';
import { globalAiState } from '@/state';

type ExtendedDocument = ProjectDocument & {
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

watch(() => props.requirementStatus, (newVal) => {
    syncFromProps(newVal);
}, { deep: true });

const localRequirements = computed(() => {
    return props.requirementStatus.map(group => ({
        ...group,
        documents: Array.from(documentsMap.value.values()).filter(d => d.type === group.key)
    }));
});

const searchQuery = ref('');
const expandedRootIds = ref<Set<string | number>>(new Set());

const {
    form, isUploadModalOpen, isEditModalOpen, openUploadModal, openEditModal,
    submitDocument, updateDocument, setDocToProcessing, targetBeingCreated
} = useDocumentActions(props, localRequirements, aiStatusMessage);

const allDocs = computed(() => Array.from(documentsMap.value.values()));
const isAiProcessing = computed(() => !!targetBeingCreated.value || allDocs.value.some(d => d.processed_at === null));

watch(isAiProcessing, (newVal) => {
    globalAiState.value.isProcessing = newVal;
}, { immediate: true });

const getDocLabel = (typeKey: string) => {
    const schema = props.project.type.document_schema || [];
    const schemaItem = schema.find((item: any) => item.key === typeKey);
    return schemaItem ? schemaItem.label : typeKey.replace(/_/g, ' ');
};

const getLeadUser = (doc: any) => {
    let user = doc.assignee || doc.user;
    if (!user && doc.assigned_id && props.project.client?.users) {
        user = props.project.client.users.find((u: any) => u.id === doc.assigned_id);
    }
    if (!user) return null;
    const f = user.first_name?.charAt(0) || '';
    const l = user.last_name?.charAt(0) || '';
    return { ...user, initials: (f + l).toUpperCase() || '??' };
};

const buildTree = (parentId: string | number | null = null): ExtendedDocument[] => {
    return allDocs.value
        .filter(d => d.parent_id === parentId || (parentId === null && d.type === 'intake'))
        .map(d => ({
            ...d,
            children: buildTree(d.id)
        })) as ExtendedDocument[];
};

const documentTree = computed(() => {
    const query = searchQuery.value.toLowerCase().trim();
    const fullTree = buildTree();
    if (!query) return fullTree;

    const filterNodes = (nodes: any[]): any[] => {
        return nodes.map(node => {
            const filteredChildren = filterNodes(node.children || []);
            const nameMatch = node.name.toLowerCase().includes(query);
            if (nameMatch || filteredChildren.length > 0) {
                return { ...node, children: filteredChildren };
            }
            return null;
        }).filter(n => n !== null);
    };
    return filterNodes(fullTree);
});

const toggleRoot = (id: string | number) => {
    if (expandedRootIds.value.has(id)) expandedRootIds.value.delete(id);
    else expandedRootIds.value.add(id);
};

useEcho(`project.${props.project.id}`, ['.document.vectorized', '.DocumentProcessingUpdate'], (payload: any) => {
    const docId = payload.document_id || payload.document?.id;
    if (!docId) return;

    const doc = documentsMap.value.get(docId);

    // 1. Check for the error message
    if (payload.statusMessage && payload.statusMessage.toLowerCase().includes('error')) {
        if (doc) {
            doc.processingError = payload.statusMessage;
        }
    }

    // 2. Handle document object updates
    if (payload.document) {
        // Keep the existing processingError if it exists, so it doesn't "flash" away
        const existingError = documentsMap.value.get(docId)?.processingError;

        const updatedDoc = {
            ...payload.document,
            processingError: existingError || null
        };

        documentsMap.value.set(docId, updatedDoc);
    }
}, [props.project.id], 'private');

const handleReprocess = (id: string | number) => {
    const doc = documentsMap.value.get(id);
    if (doc) doc.processingError = null;
    setDocToProcessing(id);
};

const onDeleteRequested = (doc: ExtendedDocument) => {
    emit('confirmDelete', doc);
};
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
                <div v-for="intake in documentTree" :key="intake.id">
                    <div class="grid grid-cols-12 items-center px-10 py-5 hover:bg-slate-50/50 transition-colors">
                        <div class="col-span-8 flex items-center gap-3 cursor-pointer" @click="toggleRoot(intake.id)">
                            <div class="p-2.5 bg-blue-50 text-blue-600 rounded-xl"><FileText class="h-5 w-5" /></div>
                            <div class="flex-1 overflow-hidden">
                                <div class="text-sm font-bold text-slate-900 truncate">{{ intake.name }}</div>
                                <div v-if="intake.processingError" class="text-[11px] text-red-600 font-black mt-1 p-1 bg-red-50 rounded border border-red-100 inline-block uppercase tracking-tighter">{{ intake.processingError }}</div>
                                <div v-else-if="!intake.processed_at" class="text-[10px] text-blue-600 font-bold mt-0.5 animate-pulse uppercase">Syncing...</div>
                                <div v-else class="text-[9px] text-slate-400 font-bold tracking-widest uppercase mt-0.5">{{ getDocLabel(intake.type) }}</div>
                            </div>
                            <ChevronRight :class="['h-4 w-4 text-slate-300 transition-transform duration-300', { 'rotate-90': expandedRootIds.has(intake.id) }]" />
                        </div>

                        <div class="col-span-2 flex justify-center border-x border-slate-50">
                            <TooltipProvider><Tooltip><TooltipTrigger>
                                <div class="h-9 w-9 rounded-full border-2 border-white shadow-sm bg-slate-100 flex items-center justify-center overflow-hidden">
                                    <img v-if="getLeadUser(intake)?.avatar" :src="getLeadUser(intake).avatar" class="object-cover h-full w-full" />
                                    <div v-else class="text-indigo-600 font-bold text-[10px]">{{ getLeadUser(intake)?.initials || '??' }}</div>
                                </div>
                            </TooltipTrigger><TooltipContent v-if="getLeadUser(intake)"><p class="text-xs font-bold">{{ getLeadUser(intake).first_name }}</p></TooltipContent></Tooltip></TooltipProvider>
                        </div>

                        <div class="col-span-2 flex justify-center gap-1">
                            <Button variant="ghost" size="icon" @click="openEditModal(intake)" class="h-8 w-8 text-slate-400 hover:text-indigo-600"><Edit2 class="h-3.5 w-3.5" /></Button>
                            <Button variant="ghost" size="icon" @click="handleReprocess(intake.id)" class="h-8 w-8 text-slate-400 hover:text-blue-600"><RotateCw class="h-3.5 w-3.5" /></Button>
                            <Button variant="ghost" size="icon" @click="onDeleteRequested(intake)" class="h-8 w-8 text-slate-400 hover:text-red-600"><Trash2 class="h-3.5 w-3.5" /></Button>
                        </div>
                    </div>

                    <div v-if="expandedRootIds.has(intake.id)" class="bg-slate-50/30">
                        <template v-for="(story, storyIdx) in intake.children" :key="story.id">
                            <div class="grid grid-cols-12 items-center py-4 px-10 border-l-4 border-blue-400/20 relative">
                                <div class="col-span-8 flex items-center gap-3 pl-12 relative">
                                    <div v-if="Number(storyIdx) < intake.children.length - 1 || (story.children && story.children.length > 0)" class="absolute left-6 top-0 bottom-0 w-px bg-slate-200"></div>
                                    <div v-else class="absolute left-6 top-0 h-1/2 w-px bg-slate-200"></div>
                                    <div class="absolute left-6 top-1/2 w-5 h-px bg-slate-200"></div>

                                    <div @click="openEditModal(story)" class="flex-1 flex items-center bg-white border border-slate-200 p-3 rounded-2xl shadow-sm hover:border-blue-400 cursor-pointer transition-all">
                                        <div class="p-2 bg-blue-50 text-blue-500 rounded-lg mr-4"><FileText class="h-4 w-4" /></div>
                                        <div class="flex-1 truncate text-left">
                                            <div class="text-[11px] font-black text-slate-900 uppercase truncate">{{ story.name }}</div>
                                            <div v-if="story.processingError" class="text-[10px] text-red-600 font-black mt-1 uppercase tracking-tighter">{{ story.processingError }}</div>
                                            <div v-else-if="!story.processed_at" class="text-[9px] text-blue-500 font-bold uppercase mt-0.5 animate-pulse">Processing...</div>
                                            <div v-else class="text-[9px] text-blue-500 font-bold uppercase mt-0.5 tracking-widest">{{ getDocLabel(story.type) }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-span-2 flex justify-center border-x border-slate-50/50">
                                    <div class="h-7 w-7 rounded-full bg-white border shadow-xs flex items-center justify-center text-[8px] font-bold text-blue-600">{{ getLeadUser(story)?.initials || '??' }}</div>
                                </div>
                                <div class="col-span-2 flex justify-center gap-1">
                                    <Button variant="ghost" size="icon" @click="openEditModal(story)" class="h-7 w-7 text-slate-300 hover:text-indigo-600"><Edit2 class="h-3 w-3" /></Button>
                                    <Button variant="ghost" size="icon" @click="handleReprocess(story.id)" class="h-7 w-7 text-slate-300 hover:text-blue-600"><RotateCw class="h-3 w-3" /></Button>
                                    <Button variant="ghost" size="icon" @click="onDeleteRequested(story)" class="h-7 w-7 text-slate-300 hover:text-red-600"><Trash2 class="h-3 w-3" /></Button>
                                </div>
                            </div>

                            <div v-for="(spec, sIdx) in story.children" :key="spec.id" class="grid grid-cols-12 items-center py-2 px-10 border-l-4 border-indigo-400/10 relative">
                                <div class="col-span-8 flex items-center gap-3 pl-24 relative">
                                    <div v-if="Number(storyIdx) < intake.children.length - 1" class="absolute left-6 top-0 bottom-0 w-px bg-slate-200"></div>
                                    <div class="absolute left-[24px]" :class="[Number(sIdx) < story.children.length - 1 ? 'top-0 bottom-0' : 'top-0 h-1/2', 'w-px bg-slate-200']"></div>
                                    <div class="absolute left-[24px] top-1/2 w-4 h-px bg-slate-200"></div>
                                    <div @click="openEditModal(spec)" class="flex-1 flex items-center bg-white/50 border border-slate-100 p-2 rounded-xl hover:border-indigo-300 cursor-pointer">
                                        <div class="p-1.5 bg-slate-50 text-slate-400 rounded-md mr-3"><FileText class="h-3.5 w-3.5" /></div>
                                        <div class="flex-1 truncate text-left">
                                            <div class="text-[10px] font-bold text-slate-600 uppercase truncate">{{ spec.name }}</div>
                                            <div v-if="spec.processingError" class="text-[9px] text-red-500 font-bold mt-1 uppercase tracking-tighter">{{ spec.processingError }}</div>
                                            <div v-else-if="!spec.processed_at" class="text-[8px] text-indigo-400 font-bold uppercase animate-pulse">Updating...</div>
                                            <div v-else class="text-[8px] text-slate-400 uppercase tracking-tighter">{{ getDocLabel(spec.type) }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-span-2 border-x border-slate-50/50"></div>
                                <div class="col-span-2 flex justify-center gap-1">
                                    <Button variant="ghost" size="icon" @click="openEditModal(spec)" class="h-6 w-6 text-slate-200 hover:text-indigo-600"><Edit2 class="h-3 w-3" /></Button>
                                    <Button variant="ghost" size="icon" @click="onDeleteRequested(spec)" class="h-6 w-6 text-slate-200 hover:text-red-500"><Trash2 class="h-3 w-3" /></Button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <DocumentFormModal v-model:open="isUploadModalOpen" mode="create" :form="form" :requirement-status="props.requirementStatus" :users="project.client?.users || []" @submit="submitDocument" />
    <DocumentFormModal v-model:open="isEditModalOpen" mode="edit" :form="form" :requirement-status="props.requirementStatus" :users="project.client?.users || []" @submit="updateDocument" />
</template>

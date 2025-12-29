<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import axios, { AxiosError } from 'axios';
import {
    PlusIcon,
    Sparkles,
    Search,
    Loader2,
    RefreshCw,
    CheckCircle2
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import DocumentRequirementSection from './DocumentRequirementSection.vue';

const props = defineProps<{
    project: any;
    requirementStatus: any[];
    canGenerate: boolean;
    isGenerating: boolean;
    projectDocumentsRoutes: any;
}>();

const emit = defineEmits(['confirmDelete', 'generate']);

// --- UI State ---
const searchQuery = ref('');
const isUploadModalOpen = ref(false);
const isEditModalOpen = ref(false);
const editingDocumentId = ref<number | null>(null);
const expandedDocId = ref<string | number | null>(null);
const selectedTypes = ref<string[]>(props.requirementStatus.map(r => r.key));
const showSuccessToast = ref(false);

// --- Form Logic ---
const form = useForm({
    name: '',
    type: '',
    content: '',
});

// --- Toast Logic ---
const triggerToast = () => {
    showSuccessToast.value = true;
    setTimeout(() => { showSuccessToast.value = false; }, 3000);
};

// --- Add Logic ---
const openUploadModal = (requirement?: any) => {
    form.reset();
    form.clearErrors();
    if (requirement) {
        form.type = requirement.key;
        form.name = `New ${requirement.label.replace(/s$/, '')}`;
    }
    isUploadModalOpen.value = true;
};

const submitDocument = async () => {
    form.processing = true;
    const formData = { ...form.data() };
    isUploadModalOpen.value = false;

    try {
        const url = props.projectDocumentsRoutes.store.url(props.project.id);
        await axios.post(url, formData);
        form.reset();
        router.reload({
            only: ['project', 'requirementStatus'],
            onFinish: () => {
                form.processing = false;
                triggerToast();
            }
        });
    } catch (err: any) {
        form.processing = false;
        const error = err as AxiosError<{ errors: any }>;
        if (error.response?.status === 422) {
            form.errors = error.response.data.errors;
            isUploadModalOpen.value = true;
        }
    }
};

// --- Edit Logic ---
const openEditModal = (doc: any) => {
    form.clearErrors();
    editingDocumentId.value = doc.id;
    form.name = doc.name;
    form.type = doc.type;
    form.content = doc.content || '';
    isEditModalOpen.value = true;
};

const updateDocument = async () => {
    form.processing = true;

    try {
        const url = props.projectDocumentsRoutes.update.url({
            project: props.project.id,
            document: editingDocumentId.value
        });
        // Change .put to .post and add _method: 'put'
        await axios.post(url, {
            ...form.data(),
            _method: 'put'
        });

        isEditModalOpen.value = false;
        form.reset();

        router.reload({
            only: ['project', 'requirementStatus'],
            onFinish: () => {
                form.processing = false;
                triggerToast();
            }
        });
    } catch (err: any) {
        form.processing = false;
        const error = err as AxiosError<{ errors: any }>;
        if (error.response?.status === 422) {
            form.errors = error.response.data.errors;
            isEditModalOpen.value = true;
        }
    }
};

// --- Filtering & UI Logic ---
const toggleType = (key: string) => {
    const index = selectedTypes.value.indexOf(key);
    if (index > -1) selectedTypes.value.splice(index, 1);
    else selectedTypes.value.push(key);
};

const filteredRequirements = computed(() => {
    const query = searchQuery.value.toLowerCase();
    return props.requirementStatus
        .filter(req => selectedTypes.value.includes(req.key))
        .map(req => ({
            ...req,
            documents: req.documents.filter((doc: any) =>
                doc.name.toLowerCase().includes(query) ||
                (doc.content && doc.content.toLowerCase().includes(query))
            )
        }))
        .filter(req => req.documents.length > 0 || (query === '' && selectedTypes.value.includes(req.key)));
});

const toggleExpand = (id: string | number) => {
    expandedDocId.value = expandedDocId.value === id ? null : id;
};
</script>

<template>
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
                <button v-for="req in requirementStatus" :key="req.key" @click="toggleType(req.key)" :class="['px-2.5 py-1 rounded-md border text-[10px] font-bold uppercase tracking-tight transition-all flex items-center gap-2', selectedTypes.includes(req.key) ? 'bg-white border-slate-300 text-slate-900 shadow-sm' : 'bg-transparent border-transparent text-slate-400 hover:text-slate-600']">
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
                @open-upload="openUploadModal"
                @open-edit="openEditModal"
                @toggle-expand="toggleExpand"
                @confirm-delete="(doc) => emit('confirmDelete', doc)"
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

        <Dialog :open="isUploadModalOpen" @update:open="isUploadModalOpen = $event">
            <DialogContent class="sm:max-w-[525px]">
                <DialogHeader>
                    <DialogTitle>Add Document</DialogTitle>
                    <DialogDescription>Create a new document for this project.</DialogDescription>
                </DialogHeader>
                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label for="name">Document Name</Label>
                        <Input id="name" v-model="form.name" :class="{ 'border-red-500': form.errors.name }" />
                        <p v-if="form.errors.name" class="text-[11px] text-red-500 font-medium">{{ form.errors.name }}</p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="type">Category</Label>
                        <select id="type" v-model="form.type" class="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm" :class="{ 'border-red-500': form.errors.type }">
                            <option value="" disabled>Select a category</option>
                            <option v-for="req in requirementStatus" :key="req.key" :value="req.key">{{ req.label }}</option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="content">Content</Label>
                        <textarea id="content" v-model="form.content" class="flex min-h-[150px] w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"></textarea>
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="isUploadModalOpen = false">Cancel</Button>
                    <Button @click="submitDocument" :disabled="form.processing" class="bg-indigo-600">Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="isEditModalOpen" @update:open="isEditModalOpen = $event">
            <DialogContent class="sm:max-w-[525px]">
                <DialogHeader>
                    <DialogTitle>Edit Document</DialogTitle>
                    <DialogDescription>Update the details of your document.</DialogDescription>
                </DialogHeader>
                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label for="edit-name">Document Name</Label>
                        <Input id="edit-name" v-model="form.name" :class="{ 'border-red-500': form.errors.name }" />
                        <p v-if="form.errors.name" class="text-[11px] text-red-500 font-medium">{{ form.errors.name }}</p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="edit-type">Category</Label>
                        <select id="edit-type" v-model="form.type" class="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm">
                            <option v-for="req in requirementStatus" :key="req.key" :value="req.key">{{ req.label }}</option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="edit-content">Content</Label>
                        <textarea id="edit-content" v-model="form.content" class="flex min-h-[200px] w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm focus:ring-2 focus:ring-slate-950"></textarea>
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="isEditModalOpen = false">Cancel</Button>
                    <Button @click="updateDocument" :disabled="form.processing" class="bg-indigo-600">Update</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <transition enter-active-class="transform ease-out duration-300 transition" enter-from-class="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2" enter-to-class="translate-y-0 opacity-100 sm:translate-x-0" leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="showSuccessToast" class="fixed bottom-8 right-8 z-[100] bg-gray-900 text-white px-5 py-3 rounded-xl shadow-2xl flex items-center gap-3 border border-gray-700">
                <CheckCircle2 class="w-5 h-5 text-green-400" />
                <span class="text-sm font-semibold tracking-tight">Changes synced successfully</span>
            </div>
        </transition>
    </div>
</template>

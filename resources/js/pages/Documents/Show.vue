<script setup lang="ts">
/* ---------------------------
   1. Imports & Types
---------------------------- */
import { computed, ref, watch, type ComputedRef } from 'vue';
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import { CheckCircle2, Trash2, Edit2, RefreshCw, ArrowLeft } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { toast } from 'vue-sonner';
import InlineDocumentForm from '@/components/documents/InlineDocumentForm.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import projectRoutes from '@/routes/projects/index';
import projectDocumentsRoutes from '@/routes/projects/documents/';
import { useForm } from '@inertiajs/vue3';
import { useDocumentActions } from '@/composables/useDocumentActions';
import { formatDate } from '@/lib/utils';
import { STATUS_LABELS, PRIORITY_LABELS, statusDotClasses, priorityDotClasses } from '@/lib/constants';
import { type BreadcrumbItem } from '@/types';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { nextTick } from 'vue';

type DocumentMetadata = { criteria: string[] };
type AcceptableSelectValue = string | number | null;

/* ---------------------------
   2. Props
---------------------------- */
const props = defineProps<{
    project: Project;
    item: ExtendedDocument;
    requirementStatus?: unknown[];
}>();

/* ---------------------------
   3. Safe metadata parsing & form
---------------------------- */
const safeJsonParse = (data: unknown, fallback: DocumentMetadata = { criteria: [] }): DocumentMetadata => {
    if (!data) return fallback;
    if (typeof data !== 'string') return data as DocumentMetadata;
    try { return JSON.parse(data) as DocumentMetadata; }
    catch (e) { console.warn(`[DocumentView] Failed to parse metadata for item ${props.item?.id}:`, e); return fallback; }
};

const documentForm = useForm({
    name: props.item.name,
    content: props.item.content,
    type: props.item.type,
    assignee_id: props.item.assignee_id,
    project_id: props.project.id,
    metadata: safeJsonParse(props.item.metadata)
});
const form = documentForm;

/* ---------------------------
   4. Reactive & computed state
---------------------------- */
const isEditing = ref(false);
const isDeleteModalOpen = ref(false);
const isDeleting = ref(false);

const parsedMetadata: ComputedRef<DocumentMetadata> = computed(() =>
    safeJsonParse(props.item?.metadata)
);

const dueAtProxy = computed<string>({
    get: () => props.item.due_at?.substring(0, 10) ?? '',
    set: (val) => patchField(props.item.id, { due_at: val || null })
});

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Projects', href: projectRoutes.index.url() },
    { title: props.project.name, href: projectRoutes.show(props.project.id).url },
    { title: props.item.name, href: '' }
]);

/* ---------------------------
   5. Document actions
---------------------------- */
const { patchField } = useDocumentActions(
    { project: props.project, projectDocumentsRoutes, requirementStatus: props.requirementStatus || [] },
    ref([]),
    ref('')
);

const toggleEdit = () => { isEditing.value = !isEditing.value; if (!isEditing.value) form.reset(); }

const handleFormSubmit = () => {
    const url = projectDocumentsRoutes.update({ project: props.project.id, document: props.item.id }).url;
    form.put(url, {
        preserveScroll: true,
        onSuccess: async() => {
            isEditing.value = false;
            await nextTick();
            setTimeout(() => toast.success('Document updated successfully'), 100);
        },
        onError: (errors) => { console.error(errors); toast.error('Failed to update document'); }
    });
}

const handleFieldChange = (fieldName: keyof ExtendedDocument | string, val: unknown) => {
    let value: AcceptableSelectValue = null;
    if (val === 'unassigned') value = null;
    else if (typeof val === 'string' || typeof val === 'number') value = val;
    else if (typeof val === 'bigint') value = Number(val);
    else return console.warn('[DocumentView] Unexpected Select value', val);

    patchField(props.item.id, { [fieldName]: value });
}

const openDeleteModal = () => { isDeleteModalOpen.value = true; }

const confirmFinalDeletion = () => {
    isDeleting.value = true;
    const url = projectDocumentsRoutes.destroy({ project: props.project.id, document: props.item.id }).url;
    router.delete(url, {
        onSuccess: () => toast.success('Document deleted'),
        onFinish: () => { isDeleting.value = false; isDeleteModalOpen.value = false; }
    });
}

/* ---------------------------
   6. Watchers
---------------------------- */
const page = usePage<{ flash?: { success?: string; error?: string } }>();
watch(() => page.props.flash, (flash) => {
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
}, { deep: true, immediate: true });

/* ---------------------------
   7. Helpers
---------------------------- */
const getDocLabel = (typeKey: string): string => {
    const schema = props.project.type?.document_schema || [];
    const found = schema.find((item) => item.key === typeKey);
    return found?.label || typeKey.replace(/_/g, ' ');
};
</script>



<template>
    <Head :title="item.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-screen bg-white">
            <nav class="border-b bg-slate-50/50 px-8 py-4">
                <div class="max-w-7xl mx-auto flex items-center justify-between">
                    <div class="flex items-center gap-4 text-sm text-slate-500">
                        <Link :href="projectRoutes.show(project.id)" class="hover:text-indigo-600 transition-colors flex items-center gap-2">
                            <ArrowLeft class="w-3 h-3" /> {{ project.name }}
                        </Link>
                        <span class="text-slate-300">/</span>
                        <span class="font-medium text-slate-900 truncate max-w-[300px]">{{ item.name }}</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button variant="outline" size="sm" @click="toggleEdit" class="h-8 text-[10px] font-black uppercase tracking-widest px-4">
                            <Edit2 class="h-3 w-3 mr-2" /> {{ isEditing ? 'Cancel' : 'Edit' }}
                        </Button>
                        <Button variant="ghost" size="icon" @click="openDeleteModal" class="h-8 w-8 text-slate-400 hover:text-red-600 hover:bg-red-50">
                            <Trash2 class="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </nav>

            <main class="max-w-7xl mx-auto px-8 py-10">
                <div class="grid grid-cols-12 gap-12">
                    <div class="col-span-12 lg:col-span-8 space-y-12">
                        <div v-if="isEditing" class="bg-slate-50 rounded-2xl p-6 border border-slate-200">
                            <InlineDocumentForm mode="edit" :form="form" :requirement-status="requirementStatus" @submit="handleFormSubmit" @cancel="toggleEdit" />
                        </div>

                        <div v-else class="space-y-12">
                            <section>
                                <div class="flex items-center gap-3 mb-6">
                                    <h3 class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-800">{{ getDocLabel(item.type) }}</h3>
                                </div>
                                <div class="text-[15px] text-slate-600 leading-relaxed prose prose-slate max-w-none" v-html="item.content || 'No description provided.'"></div>
                            </section>

                            <section v-if="parsedMetadata?.criteria?.length">
                                <h3 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-6 flex items-center gap-2">
                                    <div class="w-4 h-px bg-slate-200"></div> Success Criteria
                                </h3>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div v-for="(criterion, index) in parsedMetadata.criteria" :key="index" class="flex items-start gap-3 p-4 rounded-xl border border-slate-100 bg-slate-50/30 group hover:border-emerald-100 transition-colors">
                                        <CheckCircle2 class="h-4 w-4 text-emerald-500 mt-0.5 shrink-0" />
                                        <span class="text-[13px] text-slate-600 leading-snug">{{ criterion }}</span>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>

                    <aside class="col-span-12 lg:col-span-4">
                        <div class="sticky top-10 space-y-6">
                            <div class="bg-slate-50 rounded-3xl border border-slate-200 p-8 space-y-8">
                                <div>
                                    <h4 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-4">Properties</h4>
                                    <div class="space-y-5">
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-slate-500">Category</span>
                                            <span class="font-black uppercase tracking-wider text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded text-[9px] border border-indigo-100">
                                                {{ getDocLabel(item.type) }}
                                            </span>
                                        </div>

                                        <div class="flex flex-col">
                                            <div class="flex justify-between items-center h-[24px]">
                                                <span class="text-slate-500 text-xs">Assignee</span>
                                                <Select :model-value="item.assignee_id?.toString() ?? 'unassigned'" @update:model-value="(val) => handleFieldChange('assignee_id', val)">
                                                    <SelectTrigger class="h-auto p-0 border-none bg-transparent hover:bg-slate-100 rounded-md transition-all shadow-none w-auto outline-none">
                                                        <div class="px-2 py-1">
                                                            <span class="relative left-[10px] font-black uppercase tracking-[0.12em] text-slate-700 text-[10px]"><SelectValue /></span>
                                                        </div>
                                                    </SelectTrigger>
                                                    <SelectContent align="end" class="min-w-[160px]">
                                                        <SelectItem value="unassigned" class="text-[10px] uppercase font-bold text-slate-400">Unassigned</SelectItem>
                                                        <SelectItem v-for="user in project.client.users" :key="user.id" :value="user.id.toString()" class="text-[10px] uppercase font-bold">{{ user.name }}</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div class="flex justify-between items-center h-[24px]">
                                                <span class="text-slate-500 text-xs">Due Date</span>
                                                <div class="flex items-center hover:bg-slate-100 pl-2 pr-1 rounded transition-colors cursor-pointer mr-[-3px]">
                                                    <input type="date" v-model="dueAtProxy" class="custom-date-input bg-transparent border-none p-0 text-[10px] font-black uppercase tracking-[0.12em] text-slate-700 focus:ring-0 cursor-pointer w-[95px] text-right" />
                                                </div>
                                            </div>

                                            <div class="flex justify-between items-center h-[24px]">
                                                <span class="text-slate-500 text-xs">Priority</span>
                                                <Select :model-value="item.priority" @update:model-value="(val) => handleFieldChange('priority', val)">
                                                    <SelectTrigger class="h-auto p-0 border-none bg-transparent hover:bg-slate-100 rounded-md transition-all shadow-none w-auto outline-none">
                                                        <div class="px-2 py-1">
                                                            <span class="relative left-[10px] font-black uppercase tracking-[0.12em] text-slate-700 text-[10px] flex items-center">
                                                                <SelectValue />
                                                                <div :class="[priorityDotClasses[item.priority ?? 'low'], 'w-2 h-2 rounded-full ml-2 flex-shrink-0']"></div>
                                                            </span>
                                                        </div>
                                                    </SelectTrigger>
                                                    <SelectContent align="end" class="min-w-[160px]">
                                                        <SelectItem v-for="(label, key) in PRIORITY_LABELS" :key="key" :value="key" class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-700 cursor-pointer focus:bg-slate-50">
                                                            <div class="flex items-center justify-between w-full"><span>{{ label }}</span><div :class="[priorityDotClasses[key], 'w-2 h-2 rounded-full ml-4 flex-shrink-0']"></div></div>
                                                        </SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div class="flex justify-between items-center h-[24px]">
                                                <span class="text-slate-500 text-xs">Status</span>
                                                <Select :model-value="item.task_status ?? 'todo'" @update:model-value="(val) => handleFieldChange('task_status', val)">
                                                    <SelectTrigger class="h-auto p-0 border-none bg-transparent hover:bg-slate-100 rounded-md transition-all shadow-none w-auto outline-none">
                                                        <div class="px-2 py-1">
                                                            <span class="relative left-[10px] font-black uppercase tracking-[0.12em] text-slate-700 text-[10px] flex items-center">
                                                                <SelectValue />
                                                                <div :class="[statusDotClasses[item.task_status ?? 'todo'], 'w-2 h-2 rounded-full ml-2 flex-shrink-0']"></div>
                                                            </span>
                                                        </div>
                                                    </SelectTrigger>
                                                    <SelectContent align="end" class="min-w-[160px]">
                                                        <SelectItem v-for="(label, key) in STATUS_LABELS" :key="key" :value="key" class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-700 cursor-pointer">
                                                            <div class="flex items-center justify-between w-full min-w-[120px]"><span>{{ label }}</span><div :class="[statusDotClasses[key], 'w-2 h-2 rounded-full ml-4 flex-shrink-0']"></div></div>
                                                        </SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>

                                        <div class="flex items-center justify-between text-xs pt-2">
                                            <span class="text-slate-500">AI Status</span>
                                            <div v-if="item.currentStatus || item.processed_at === null" class="flex items-center gap-1.5 text-indigo-600 animate-pulse">
                                                <RefreshCw class="h-3 w-3 animate-spin" />
                                                <span class="font-black uppercase text-[9px]">{{ item.currentStatus || 'Processing' }}</span>
                                            </div>
                                            <span v-else class="text-emerald-600 font-bold uppercase text-[9px]">Analyzed</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="pt-6 border-t border-slate-200">
                                    <h4 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-4">Dates</h4>
                                    <div class="space-y-4">
                                        <div class="flex items-center justify-between text-[10px] uppercase tracking-wider">
                                            <span class="text-slate-500">Created</span>
                                            <div class="flex items-center gap-1.5 font-bold">
                                                <span class="text-slate-700">{{ formatDate(item.created_at) }}</span>
                                                <span v-if="item.creator?.name" class="text-slate-400 font-medium lowercase italic">by</span>
                                                <span v-if="item.creator?.name" class="text-indigo-600">{{ item.creator?.name }}</span>
                                            </div>
                                        </div>
                                        <div class="flex items-center justify-between text-[10px] uppercase tracking-wider">
                                            <span class="text-slate-500">Last Updated</span>
                                            <div class="flex items-center gap-1.5 font-bold">
                                                <span class="text-slate-700">{{ formatDate(item.updated_at) }}</span>
                                                <span class="text-slate-400 font-medium lowercase italic">by</span>
                                                <span class="text-indigo-600">{{ item.editor?.name }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>
            </main>
        </div>

        <ConfirmDeleteModal
            :open="isDeleteModalOpen"
            title="Delete Document"
            :description="`Are you sure you want to delete '${item.name}'? This action cannot be undone.`"
            :loading="isDeleting"
            @confirm="confirmFinalDeletion"
            @close="isDeleteModalOpen = false"
        />
    </AppLayout>
</template>

<style scoped>
/* Scrollbar & Date Indicator */
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
input[type="date"]::-webkit-calendar-picker-indicator {
    filter: invert(48%) sepia(13%) saturate(541%) hue-rotate(186deg) brightness(96%) contrast(88%);
    cursor: pointer;
}

/* Global Reset for Select chaos */
:deep([data-radix-collection-item]),
:deep(button[role="combobox"]),
.custom-date-input:focus {
    outline: none !important;
    box-shadow: none !important;
    border-color: transparent !important;
}

:deep(button[role="combobox"]:focus) {
    background-color: rgb(241 245 249);
}
</style>

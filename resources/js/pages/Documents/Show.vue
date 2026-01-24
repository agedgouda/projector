<script setup lang="ts">
/* ---------------------------
   1. Imports & Types
---------------------------- */
import { computed, ref, watch, type ComputedRef } from 'vue';
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import { CheckCircle2, Trash2, Edit2, ArrowLeft } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { toast } from 'vue-sonner';
import InlineDocumentForm from '@/components/documents/InlineDocumentForm.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import DocumentSidebar from './Partials/DocumentSidebar.vue';
import projectRoutes from '@/routes/projects/index';
import projectDocumentsRoutes from '@/routes/projects/documents/';
import { useForm } from '@inertiajs/vue3';
import { useDocumentActions } from '@/composables/useDocumentActions';
import { type BreadcrumbItem } from '@/types';
import { nextTick } from 'vue';

type DocumentMetadata = { criteria: string[] };


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
const { patchField, updateField } = useDocumentActions(
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
                            <DocumentSidebar
                                :item="item"
                                :project="project"
                                v-model:dueAtProxy="dueAtProxy"
                                @change="(field, val) => updateField(item.id, field, val)"
                            />

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

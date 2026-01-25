<script setup lang="ts">
/* ---------------------------
   1. Imports & Types
---------------------------- */
import { computed, ref, watch, type ComputedRef, nextTick } from 'vue';
import { Head, usePage, router, Link } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

// Layouts & Components
import AppLayout from '@/layouts/AppLayout.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import DocumentSidebar from './Partials/DocumentSidebar.vue';
import DocumentContent from './Partials/DocumentContent.vue'; // New decomposed content partial
import { Trash2, Edit2, ArrowLeft, X } from 'lucide-vue-next';
// Routes & Logic
import projectRoutes from '@/routes/projects/index';
import projectDocumentsRoutes from '@/routes/projects/documents/';
import { useDocumentActions } from '@/composables/useDocumentActions';
import { type BreadcrumbItem } from '@/types';

type DocumentMetadata = { criteria: string[] };

/* ---------------------------
   2. Props
---------------------------- */
const props = defineProps<{
    project: Project;
    item: ExtendedDocument;
}>();

/* ---------------------------
   3. Document Actions Setup
---------------------------- */


const {
    updateField,
    safeJsonParse
} = useDocumentActions(
    {
        project: props.project,
        projectDocumentsRoutes,
        requirementStatus:  []
    },
    ref([]),
    ref('')
);

/* ---------------------------
   4. Reactive & Computed State
---------------------------- */
const isEditing = ref(false);
const isDeleteModalOpen = ref(false);
const isDeleting = ref(false);

const parsedMetadata: ComputedRef<DocumentMetadata> = computed(() =>
    safeJsonParse(props.item?.metadata)
);

const form = useForm({
    id: props.item.id, // Add this line
    name: props.item.name,
    content: props.item.content,
    type: props.item.type,
    assignee_id: props.item.assignee_id,
    project_id: props.project.id,
    metadata: parsedMetadata.value
});

const dueAtProxy = computed<string>({
    get: () => props.item.due_at?.substring(0, 10) ?? '',
    set: (val) => updateField(props.item.id, 'due_at', val)
});



const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Projects', href: projectRoutes.index.url() },
    { title: props.project.name, href: projectRoutes.show(props.project.id).url },
    { title: props.item.name, href: '' }
]);

/* ---------------------------
   5. Action Handlers
---------------------------- */
const toggleEdit = () => {
    isEditing.value = !isEditing.value;
    if (!isEditing.value) form.reset();
};

const handleFormSubmit = () => {
    const url = projectDocumentsRoutes.update({
        project: props.project.id,
        document: props.item.id
    }).url;

    form.put(url, {
        preserveScroll: true,
        onSuccess: async () => {
            isEditing.value = false;
            await nextTick();
            setTimeout(() => toast.success('Document updated successfully'), 100);
        },
        onError: (errors) => {
            console.error(errors);
            toast.error('Failed to update document');
        }
    });
};

const openDeleteModal = () => { isDeleteModalOpen.value = true; };

const confirmFinalDeletion = () => {
    isDeleting.value = true;
    const url = projectDocumentsRoutes.destroy({
        project: props.project.id,
        document: props.item.id
    }).url;

    router.delete(url, {
        onSuccess: () => toast.success('Document deleted'),
        onFinish: () => {
            isDeleting.value = false;
            isDeleteModalOpen.value = false;
        }
    });
};

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

</script>



<template>
    <Head :title="item.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-screen bg-white">
            <nav class="border-b bg-slate-50/50 px-8 py-4">
                <div class="max-w-7xl mx-auto flex items-center justify-between">
                    <div class="flex items-center gap-4 text-sm text-slate-500">
                        <Link
                            :href="projectRoutes.show(project.id).url"
                            class="hover:text-indigo-600 transition-colors flex items-center gap-2"
                        >
                            <ArrowLeft class="w-3 h-3" />
                            {{ project.name }}
                        </Link>
                        <span class="text-slate-300">/</span>
                        <span class="font-medium text-slate-900 truncate max-w-[300px]">{{ item.name }}</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="toggleEdit"
                            class="relative h-8 w-24 text-[10px] font-black uppercase tracking-widest flex items-center justify-center transition-all duration-200"
                        >
                            <div class="absolute left-3 flex items-center justify-center">
                                <component
                                    :is="isEditing ? X : Edit2"
                                    class="h-3 w-3 transition-transform duration-200"
                                    :class="{ 'rotate-90': isEditing }"
                                />
                            </div>

                            <span class="ml-4">
                                {{ isEditing ? 'Cancel' : 'Edit' }}
                            </span>
                        </Button>

                        <Button
                            variant="ghost"
                            size="icon"
                            @click="openDeleteModal"
                            class="h-8 w-8 p-0 text-slate-400 hover:text-red-600 hover:bg-red-50 flex items-center justify-center"
                        >
                            <Trash2 class="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </nav>


            <main class="max-w-7xl mx-auto px-8 py-10">
                <div class="grid grid-cols-12 gap-12">
                    <DocumentContent
                        class="col-span-12 lg:col-span-8"
                        :item="item"
                        :project="project"
                        :is-editing="isEditing"
                        :metadata="parsedMetadata"
                        :form="form"
                        @submit="handleFormSubmit"
                        @cancel="toggleEdit"
                    />

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

<script setup lang="ts">
/* ---------------------------
   1. Imports & Types
---------------------------- */
import { computed, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Trash2, Edit2, ArrowLeft, X } from 'lucide-vue-next';

// Layouts & Components
import AppLayout from '@/layouts/AppLayout.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import DocumentSidebar from './Partials/DocumentSidebar.vue';
import DocumentContent from './Partials/DocumentContent.vue';

// Composables
import { useDocumentActions } from '@/composables/useDocumentActions';
import { useDocumentForm } from '@/composables/documents/useDocumentForm';
import { useDocumentNavigation } from '@/composables/documents/useDocumentNavigation';

/* ---------------------------
   2. Props
---------------------------- */
const props = defineProps<{
    project: Project;
    item: ExtendedDocument;
}>();

/* ---------------------------
   3. Logic Setup (Composables)
---------------------------- */
const {
    form,
    isEditing,
    isDeleting,
    isDeleteModalOpen,
    toggleEdit,
    handleFormSubmit,
    confirmDeletion
} = useDocumentForm(props.project, props.item);

const { breadcrumbs, handleBack } = useDocumentNavigation(props.project, props.item);

const { updateField } = useDocumentActions(
    { project: props.project, documentSchema: [] },
    ref('')
);

/* ---------------------------
   4. Local UI State
---------------------------- */
// Proxy for the date picker to ensure it formats correctly for the input[type="date"]
const dueAtProxy = computed<string>({
    get: () => props.item.due_at?.substring(0, 10) ?? '',
    set: (val) => updateField(props.item.id as string, 'due_at', val)
});

/* ---------------------------
   5. Watchers (Flash Messages)
---------------------------- */
const page = usePage<{ flash?: { success?: string; error?: string } }>();
watch(() => page.props.flash, (flash) => {
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
}, { deep: true, immediate: true });

</script>

<template>
    <Head :title="item.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-screen bg-white">
            <nav class="border-b bg-slate-50/50 px-8 py-4">
                <div class="max-w-7xl mx-auto flex items-center justify-between">
                    <div class="flex items-center gap-4 text-sm text-slate-500">
                        <button
                            @click="handleBack"
                            class="hover:text-indigo-600 transition-colors flex items-center gap-2 cursor-pointer bg-transparent border-0 p-0"
                        >
                            <ArrowLeft class="w-3 h-3" />
                            {{ project.name }}
                        </button>
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
                            @click="isDeleteModalOpen = true"
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
                        :metadata="form.metadata"
                        :form="form"
                        @submit="() => handleFormSubmit()"
                        @cancel="toggleEdit"
                    />

                    <aside class="col-span-12 lg:col-span-4">
                        <DocumentSidebar
                            :item="item"
                            :project="project"
                            v-model:dueAtProxy="dueAtProxy"
                            @change="(field, val) => updateField(item.id as string, field, val)"
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
            @confirm="confirmDeletion"
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

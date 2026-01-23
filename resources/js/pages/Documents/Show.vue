<script setup lang="ts">
import { computed, ref, watch  } from 'vue';
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import {
    CheckCircle2, Trash2, Plus, Edit2, RefreshCw, ArrowLeft, Calendar
} from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';

import { toast } from 'vue-sonner';
import projectRoutes from '@/routes/projects/index';
import { type BreadcrumbItem } from '@/types';
import InlineDocumentForm from '@/components/documents/InlineDocumentForm.vue';
import { formatDate } from '@/lib/utils'
import { STATUS_LABELS, PRIORITY_LABELS, priorityClasses, statusClasses, priorityDotClasses } from '@/lib/constants'
import projectDocumentsRoutes from '@/routes/projects/documents/';
import { useForm } from '@inertiajs/vue3';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue
} from '@/components/ui/select';


const props = defineProps<{
    project: Project;
    item: ExtendedDocument;
    requirementStatus?: any[];
    form?: any;
}>();

const form = useForm({
    name: props.item.name,
    content: props.item.content,
    type: props.item.type,
    assignee_id: props.item.assignee_id,
    project_id: props.project.id,
    metadata: props.item.metadata ?
        (typeof props.item.metadata === 'string' ? JSON.parse(props.item.metadata) : props.item.metadata)
        : { criteria: [] }
});

const page = usePage();
const isEditing = ref(false);
const toggleEdit = () => {
    isEditing.value = !isEditing.value;
    if (!isEditing.value) form.reset(); // Reset form if they cancel
};

// Sync content when item updates in the background

const getDocLabel = (typeKey: string) => {
    const schema = props.project.type?.document_schema || [];
    const found = schema.find((item: any) => item.key === typeKey);

    // If found, return label. If not, return the Key with underscores removed.
    return found?.label || typeKey.replace(/_/g, ' ');
};

const parsedMetadata = computed(() => {
    if (!props.item?.metadata) return null;
    return typeof props.item.metadata === 'string'
        ? JSON.parse(props.item.metadata)
        : props.item.metadata;
});


const handleFormSubmit = () => {
    const url = projectDocumentsRoutes.update({
        project: props.project.id,
        document: props.item.id
    }).url;

    form.put(url, {
        // preserveScroll keeps the user in the same spot
        preserveScroll: true,
        onSuccess: () => {
            isEditing.value = false;
            // Use a slight delay or nextTick if the toast is being swallowed
            setTimeout(() => {
                toast.success('Document updated successfully');
            }, 100);
        },
        onError: (errors) => {
            console.error(errors);
            toast.error('Failed to update document');
        }
    });
};

const handleAssigneeChange = (val: string) => {
    // 1. Map the 'unassigned' string back to a database-friendly null
    const newId = val === 'unassigned' ? null : val;

    // 2. Build the URL using your Wayfinder helper
    const url = projectDocumentsRoutes.update({
        project: props.project.id,
        document: props.item.id
    }).url;

    // 3. Fire the request
    router.patch(url, {
        assignee_id: newId
    }, {
        preserveScroll: true, // Prevents the page from jumping to the top
        onSuccess: () => {
            toast.success('Assignee updated');
        },
        onError: () => {
            toast.error('Failed to update assignee');
        }
    });
};

watch(() => page.props.flash, (flash: any) => {
    if (flash?.success) {
        toast.success(flash.success);
    }
    if (flash?.error) {
        toast.error(flash.error);
    }
}, { deep: true, immediate: true });

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: 'Projects',
        href: projectRoutes.index.url()
    },
    {
        title: props.project.name,
        href: projectRoutes.show(props.project.id).url
    },
    {
        title: props.item.name, // This now updates dynamically
        href: ''
    },
]);

const isDeleteModalOpen = ref(false);
const isDeleting = ref(false);

const openDeleteModal = () => {
    isDeleteModalOpen.value = true;
};

const confirmFinalDeletion = () => {
    isDeleting.value = true;

    // Using Wayfinder with the nested object structure
    const url = projectDocumentsRoutes.destroy({
        project: props.project.id,
        document: props.item.id
    }).url;

    router.delete(url, {
        onSuccess: () => {
            toast.success('Document deleted');
            // Usually Inertia redirects you here, so the modal closing is implicit
        },
        onError: () => {
            toast.error('Failed to delete the document');
        },
        onFinish: () => {
            isDeleting.value = false;
            isDeleteModalOpen.value = false;
        }
    });
};

</script>

<template>
    <Head :title="item.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-screen bg-white">
            <nav class="border-b bg-slate-50/50 px-8 py-4">
                <div class="max-w-7xl mx-auto flex items-center justify-between">
                    <div class="flex items-center gap-4 text-sm text-slate-500">
                        <Link
                            :href="projectRoutes.show(project.id)"
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
                            class="h-8 text-[10px] font-black uppercase tracking-widest px-4"
                        >
                            <Edit2 class="h-3 w-3 mr-2" /> {{ isEditing ? 'Cancel' : 'Edit' }}
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            @click="openDeleteModal"
                            class="h-8 w-8 text-slate-400 hover:text-red-600 hover:bg-red-50"
                        >
                            <Trash2 class="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </nav>

            <main class="max-w-7xl mx-auto px-8 py-10">
                <div class="grid grid-cols-12 gap-12">

                    <div class="col-span-12 lg:col-span-8 space-y-12">

                        <div v-if="isEditing" class="bg-slate-50 rounded-2xl p-6 border border-slate-200">
                            <InlineDocumentForm
                                mode="edit"
                                :form="form"
                                :requirement-status="requirementStatus"
                                @submit="handleFormSubmit"
                                @cancel="toggleEdit"
                            />
                        </div>

                        <div v-else class="space-y-12">
                            <section>
                                <div class="flex items-center gap-3 mb-6">
                                    <h3 class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-800">
                                        {{getDocLabel(item.type)}}
                                    </h3>
                                </div>
                                <div
                                    class="text-[15px] text-slate-600 leading-relaxed prose prose-slate max-w-none"
                                    v-html="item.content || 'No description provided.'"
                                ></div>
                            </section>

                            <section v-if="parsedMetadata?.criteria?.length">
                                <h3 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-6 flex items-center gap-2">
                                    <div class="w-4 h-px bg-slate-200"></div> Success Criteria
                                </h3>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div
                                        v-for="(criterion, index) in parsedMetadata.criteria"
                                        :key="index"
                                        class="flex items-start gap-3 p-4 rounded-xl border border-slate-100 bg-slate-50/30 group hover:border-emerald-100 transition-colors"
                                    >
                                        <CheckCircle2 class="h-4 w-4 text-emerald-500 mt-0.5 shrink-0" />
                                        <span class="text-[13px] text-slate-600 leading-snug">
                                            {{ criterion }}
                                        </span>
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
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-slate-500">Assignee</span>

                                        <Select :model-value="item.assignee_id?.toString() ?? 'unassigned'" @update:model-value="handleAssigneeChange">
                                            <SelectTrigger class="h-auto p-0 border-none bg-transparent hover:bg-slate-100 rounded-md transition-all shadow-none focus:ring-0 focus:ring-offset-0 w-auto outline-none">
                                                <div class="px-2 py-1">
                                                    <span class="font-black uppercase tracking-[0.12em] text-slate-700 text-[10px]">
                                                        <SelectValue />
                                                    </span>
                                                </div>
                                            </SelectTrigger>

                                            <SelectContent align="end" class="min-w-[160px]">
                                                <SelectItem value="unassigned" class="text-[10px] uppercase font-bold text-slate-400">
                                                    Unassigned
                                                </SelectItem>
                                                <SelectItem
                                                    v-for="user in project.client.users"
                                                    :key="user.id"
                                                    :value="user.id.toString()"
                                                    class="text-[10px] uppercase font-bold"
                                                >
                                                    {{ user.name }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                        <div class="flex items-center justify-between text-xs">
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
                                    <div class="space-y-4 text-[11px]">
                                        <div class="flex justify-between">
                                            <span class="text-slate-500">Created</span>
                                            <span class="text-slate-700 font-medium">{{ formatDate(item.created_at) }}</span>
                                            <span v-if="item.creator?.name" class="text-slate-400 font-medium lowercase">by</span>
                                            <span v-if="item.creator?.name" class="text-indigo-600">{{ item.creator?.name }}</span>
                                           <span v-if="item.creator?.name"  class="text-slate-700 font-medium">{{ item.creator?.name }}</span>
                                        </div>
                                        <div class="flex items-center justify-between text-[10px] uppercase tracking-wider">
                                            <span class="text-slate-500">Last Updated</span>
                                            <div class="flex items-center gap-1.5 font-bold">
                                                <span class="text-slate-700">{{ formatDate(item.updated_at) }}</span>
                                                <span class="text-slate-400 font-medium lowercase">by</span>
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
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
</style>

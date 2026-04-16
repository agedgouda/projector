<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import ResourceHeader from '@/components/ResourceHeader.vue';
import ResourceList from '@/components/ResourceList.vue';
import { type BreadcrumbItem } from '@/types';
import { Search, X, PlusIcon, Edit2, Trash2} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import aiTemplateRoutes from '@/routes/ai-templates';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { useConfirmDelete } from '@/composables/useConfirmDelete';
import { toast } from 'vue-sonner';

const props = defineProps<{
    templates: AiTemplate[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'AI Templates', href: aiTemplateRoutes.index().url },
];

const searchQuery = ref('');


const handleCreate = () => {
    router.visit(aiTemplateRoutes.create().url);
};


const handleShow = (id: string | number) => {
    // Navigate to the Show page using Wayfinder
    router.visit(aiTemplateRoutes.show({ ai_template: id }).url);
};

const {
    isModalOpen,
    itemToDelete,
    deleteForm,
    openModal,
    closeModal,
    executeDelete
} = useConfirmDelete();

const handleDelete = () => {
    if (!itemToDelete.value) return;

    executeDelete(aiTemplateRoutes.destroy.url(itemToDelete.value.id), {
        onSuccess: () => {
            itemToDelete.value = null;
            toast.success('AI Template purged from library');
        }
    });
};


// --- Filter Logic ---
const displayItems = computed(() => {
    let list = [...props.templates];

    if (searchQuery.value.trim()) {
        const query = searchQuery.value.toLowerCase();
        list = list.filter(t => t.name.toLowerCase().includes(query));
    }

    const flattened: any[] = [];
    flattened.push({ isHeader: true, name: 'Active Intelligence Protocols', count: list.length });

    list.forEach((t) => {
        flattened.push({ ...t, isHeader: false });
    });

    return flattened;
});



</script>

<template>
    <Head title="AI Templates" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white">AI Intelligence Library</h1>
                    <p class="text-sm text-gray-500">Select a protocol to view details or execute transformations.</p>
                </div>

                <Button
                    @click="handleCreate"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold h-11 px-6 rounded-xl shadow-lg shadow-indigo-500/20 active:scale-95 transition-all"
                >
                    <PlusIcon class="w-5 h-5 mr-2" />
                    New Template
                </Button>
            </div>

            <div class="flex flex-col lg:flex-row gap-4 mb-8">
                <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-white dark:bg-slate-900 p-2 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex-1">
                    <div class="relative w-full md:w-80 lg:w-96">
                        <Search class="absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
                        <Input
                            v-model="searchQuery"
                            placeholder="Search templates..."
                            class="pl-11 pr-10 bg-slate-50 dark:bg-slate-950 border-none h-11 rounded-xl focus-visible:ring-1 focus-visible:ring-slate-300"
                        />
                        <button v-if="searchQuery" @click="searchQuery = ''" class="absolute right-3 top-1/2 -translate-y-1/2 p-0.5 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-full transition-colors">
                            <X class="w-3 h-3 text-slate-500" />
                        </button>
                    </div>
                </div>
            </div>

            <div class="relative w-full">
                <div v-if="displayItems.length <= 1" class="text-center py-20 border-2 border-dashed rounded-3xl border-gray-100 dark:border-gray-800/50">
                    <p class="text-gray-400 font-medium">No AI templates found matching your criteria.</p>
                </div>

                <ResourceList :items="displayItems">
                    <template #default="{ item }">
                        <ResourceHeader
                            v-if="item.isHeader"
                            :title="item.name"
                            :count="item.count"
                            :collapsed="false"
                        />

                        <div
                            v-else
                            class="mb-3 w-full group relative p-5 bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-2xl flex items-center justify-between"
                        >
                            <div class="flex items-center gap-4">
                                <div>
                                    <h3 class="font-black uppercase tracking-tight text-gray-900 dark:text-white text-sm">{{ item.name }}</h3>
                                </div>
                            </div>

                            <div class="flex items-center gap-4">
                                <div
                                    @click="handleShow(item.id)"
                                    class="cursor-pointer text-[10px] font-black uppercase tracking-widest text-gray-300 hover:text-indigo-500 transition-colors mr-2"
                                    >
                                    <Edit2 class="w-5 h-5" />
                                </div>
                                <div
                                    @click="openModal({ id: item.id, name: item.name })"
                                    class="cursor-pointer text-[10px] font-black uppercase tracking-widest text-gray-300 hover:text-indigo-500 transition-colors mr-2"
                                    >
                                    <Trash2 class="w-5 h-5" />
                                </div>
                            </div>
                        </div>
                    </template>
                </ResourceList>
            </div>
        </div>

        <ConfirmDeleteModal
            :open="isModalOpen"
            title="Delete AI Template"
            :description="`Are you sure you want to delete '${itemToDelete?.name}'? This action cannot be undone.`"
            :loading="deleteForm.processing"
            @confirm="handleDelete"
            @close="closeModal"
        />
    </AppLayout>
</template>

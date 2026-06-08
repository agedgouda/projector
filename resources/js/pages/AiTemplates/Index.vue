<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import ResourceHeader from '@/components/ResourceHeader.vue';
import ResourceList from '@/components/ResourceList.vue';
import { type BreadcrumbItem } from '@/types';
import { Search, PlusIcon, Edit2, Trash2, Copy } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import aiTemplateRoutes from '@/routes/ai-templates';
import { duplicate as duplicateRoute } from '@/routes/ai-templates/index';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { useConfirmDelete } from '@/composables/useConfirmDelete';
import { toast } from 'vue-sonner';
import FlatRow from '@/components/FlatRow.vue';
import { FLAT_ACTION_BUTTON, FLAT_SEARCH_ICON, FLAT_SEARCH_INPUT } from '@/lib/flat-ui';

interface AiTemplateWithPerms extends AiTemplate {
    organization_id: string | null;
    can_edit: boolean;
}

const props = defineProps<{
    templates: AiTemplateWithPerms[];
}>();

const page = usePage<AppPageProps>();
const isSuperAdmin = computed(() => page.props.auth.user?.roles?.includes('super-admin') ?? false);
const isOrgAdmin = computed(() => page.props.auth.user?.roles?.includes('org-admin') ?? false);
const canCreate = computed(() => isSuperAdmin.value || isOrgAdmin.value);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'AI Templates', href: aiTemplateRoutes.index().url },
];

const searchQuery = ref('');

const handleCreate = () => {
    router.visit(aiTemplateRoutes.create().url);
};

const handleShow = (id: string | number) => {
    router.visit(aiTemplateRoutes.show({ ai_template: id }).url);
};

const handleCopy = (id: string | number) => {
    router.post(duplicateRoute.url(id));
};

const {
    isModalOpen,
    itemToDelete,
    deleteForm,
    openModal,
    closeModal,
    executeDelete,
} = useConfirmDelete();

const handleDelete = () => {
    if (!itemToDelete.value) return;

    executeDelete(aiTemplateRoutes.destroy.url(itemToDelete.value.id), {
        onSuccess: () => {
            itemToDelete.value = null;
            toast.success('AI Template purged from library');
        },
    });
};

// --- Filter Logic ---
const filtered = computed(() => {
    if (!searchQuery.value.trim()) return props.templates;
    const query = searchQuery.value.toLowerCase();
    return props.templates.filter(t => t.name.toLowerCase().includes(query));
});

const orgTemplates = computed(() => filtered.value.filter(t => t.organization_id));
const globalTemplates = computed(() => filtered.value.filter(t => !t.organization_id));

const buildSection = (items: AiTemplateWithPerms[]) => {
    const workflows = items.filter(t => t.type === 'workflow');
    const orgExtraction = items.filter(t => t.type === 'org_extraction');
    const result: any[] = [];

    if (workflows.length) {
        result.push({ isHeader: true, name: 'Workflow Templates', count: workflows.length });
        workflows.forEach(t => result.push({ ...t, isHeader: false }));
    }
    if (orgExtraction.length) {
        result.push({ isHeader: true, name: 'Org Document Extraction', count: orgExtraction.length });
        orgExtraction.forEach(t => result.push({ ...t, isHeader: false }));
    }
    return result;
};

const orgItems = computed(() => buildSection(orgTemplates.value));
const globalItems = computed(() => buildSection(globalTemplates.value));
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
                    v-if="canCreate"
                    @click="handleCreate"
                    class="font-bold h-11 px-6"
                >
                    <PlusIcon class="w-5 h-5 mr-2" />
                    New Template
                </Button>
            </div>

            <div class="mb-8">
                <div class="relative w-full md:w-80 lg:w-96 group">
                    <Search :class="FLAT_SEARCH_ICON" />
                    <Input
                        v-model="searchQuery"
                        placeholder="Search templates..."
                        :class="FLAT_SEARCH_INPUT"
                    />
                </div>
            </div>

            <div v-if="orgTemplates.length === 0 && globalTemplates.length === 0" class="text-center py-20 border-2 border-dashed rounded-3xl border-gray-100 dark:border-gray-800/50">
                <p class="text-gray-400 font-medium">No AI templates found matching your criteria.</p>
            </div>

            <div class="space-y-10">
                <!-- My Organization section -->
                <div v-if="orgItems.length > 0">
                    <div class="flex items-center gap-3 mb-4">
                        <h2 class="text-[10px] font-black uppercase tracking-[0.2em] text-projector-primary-600 dark:text-projector-primary-400">
                            My Organization
                        </h2>
                        <span class="text-[9px] font-black text-gray-400 dark:text-gray-600">{{ orgTemplates.length }}</span>
                    </div>
                    <ResourceList :items="orgItems">
                        <template #default="{ item }">
                            <ResourceHeader
                                v-if="item.isHeader"
                                :title="item.name"
                                :count="item.count"
                                :collapsed="false"
                            />
                            <FlatRow v-else height="md" clickable @click="handleShow(item.id)">
                                <span class="font-bold text-[13px] text-slate-900 dark:text-slate-100 truncate">{{ item.name }}</span>

                                <template #actions>
                                    <button type="button" @click.stop="handleShow(item.id)" :class="FLAT_ACTION_BUTTON" title="Edit template">
                                        <Edit2 class="w-3.5 h-3.5" />
                                    </button>
                                    <button v-if="canCreate" type="button" @click.stop="handleCopy(item.id)" :class="FLAT_ACTION_BUTTON" title="Duplicate template">
                                        <Copy class="w-3.5 h-3.5" />
                                    </button>
                                    <button
                                        v-if="item.can_edit"
                                        type="button"
                                        @click.stop="openModal({ id: item.id, name: item.name })"
                                        class="h-7 w-7 flex items-center justify-center rounded-md text-slate-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 opacity-0 group-hover:opacity-100 transition-colors"
                                        title="Delete template"
                                    >
                                        <Trash2 class="w-3.5 h-3.5" />
                                    </button>
                                </template>
                            </FlatRow>
                        </template>
                    </ResourceList>
                </div>

                <!-- Global Templates section -->
                <div v-if="globalItems.length > 0">
                    <div class="flex items-center gap-3 mb-4">
                        <h2 class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 dark:text-gray-500">
                            Global Templates
                        </h2>
                        <span class="text-[9px] font-black text-gray-400 dark:text-gray-600">{{ globalTemplates.length }}</span>
                    </div>
                    <ResourceList :items="globalItems">
                        <template #default="{ item }">
                            <ResourceHeader
                                v-if="item.isHeader"
                                :title="item.name"
                                :count="item.count"
                                :collapsed="false"
                            />
                            <FlatRow v-else height="md" clickable @click="handleShow(item.id)">
                                <span class="font-bold text-[13px] text-slate-900 dark:text-slate-100 truncate">{{ item.name }}</span>

                                <template #actions>
                                    <button type="button" @click.stop="handleShow(item.id)" :class="FLAT_ACTION_BUTTON" title="Edit template">
                                        <Edit2 class="w-3.5 h-3.5" />
                                    </button>
                                    <button v-if="canCreate" type="button" @click.stop="handleCopy(item.id)" :class="FLAT_ACTION_BUTTON" title="Duplicate template">
                                        <Copy class="w-3.5 h-3.5" />
                                    </button>
                                    <button
                                        v-if="item.can_edit"
                                        type="button"
                                        @click.stop="openModal({ id: item.id, name: item.name })"
                                        class="h-7 w-7 flex items-center justify-center rounded-md text-slate-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 opacity-0 group-hover:opacity-100 transition-colors"
                                        title="Delete template"
                                    >
                                        <Trash2 class="w-3.5 h-3.5" />
                                    </button>
                                </template>
                            </FlatRow>
                        </template>
                    </ResourceList>
                </div>
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

<script setup lang="ts">
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import FlatRow from '@/components/FlatRow.vue';
import ResourceHeader from '@/components/ResourceHeader.vue';
import ResourceList from '@/components/ResourceList.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useConfirmDelete } from '@/composables/useConfirmDelete';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    FLAT_ACTION_BUTTON,
    FLAT_SEARCH_ICON,
    FLAT_SEARCH_INPUT,
} from '@/lib/flat-ui';
import aiTemplateRoutes from '@/routes/transformation-library';
import { duplicate as duplicateRoute } from '@/routes/transformation-library/index';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Copy, Edit2, PlusIcon, Search, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

interface AiTemplateWithPerms extends AiTemplate {
    organization_id: string | null;
    can_edit: boolean;
}

const props = defineProps<{
    templates: AiTemplateWithPerms[];
}>();

const page = usePage<AppPageProps>();
const isSuperAdmin = computed(
    () => page.props.auth.user?.roles?.includes('super-admin') ?? false,
);
const isOrgAdmin = computed(
    () => page.props.auth.user?.roles?.includes('org-admin') ?? false,
);
const canCreate = computed(() => isSuperAdmin.value || isOrgAdmin.value);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Transformations', href: aiTemplateRoutes.index().url },
];

const searchQuery = ref('');

const handleCreate = () => {
    router.visit(aiTemplateRoutes.create().url);
};

const handleShow = (id: string | number) => {
    router.visit(aiTemplateRoutes.show({ aiTemplate: id }).url);
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
    return props.templates.filter((t) => t.name.toLowerCase().includes(query));
});

const orgTemplates = computed(() =>
    filtered.value.filter((t) => t.organization_id),
);
const globalTemplates = computed(() =>
    filtered.value.filter((t) => !t.organization_id),
);

const buildSection = (items: AiTemplateWithPerms[]) => {
    const workflows = items.filter((t) => t.type === 'workflow');
    const orgExtraction = items.filter((t) => t.type === 'org_extraction');
    const result: any[] = [];

    if (workflows.length) {
        result.push({
            isHeader: true,
            name: 'Workflow Templates',
            count: workflows.length,
        });
        workflows.forEach((t) => result.push({ ...t, isHeader: false }));
    }
    if (orgExtraction.length) {
        result.push({
            isHeader: true,
            name: 'Org Document Extraction',
            count: orgExtraction.length,
        });
        orgExtraction.forEach((t) => result.push({ ...t, isHeader: false }));
    }
    return result;
};

const orgItems = computed(() => buildSection(orgTemplates.value));
const globalItems = computed(() => buildSection(globalTemplates.value));
</script>

<template>
    <Head title="Transformations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full p-6">
            <div
                class="mb-8 flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center"
            >
                <div>
                    <h1
                        class="text-2xl font-black tracking-tight text-gray-900 dark:text-white"
                    >
                        Transformation Library
                    </h1>
                    <p class="text-sm text-gray-500">
                        Select a protocol to view details or execute
                        transformations.
                    </p>
                </div>

                <Button
                    v-if="canCreate"
                    @click="handleCreate"
                    class="h-11 px-6 font-bold"
                >
                    <PlusIcon class="mr-2 h-5 w-5" />
                    New Transformation
                </Button>
            </div>

            <div class="mb-8">
                <div class="group relative w-full md:w-80 lg:w-96">
                    <Search :class="FLAT_SEARCH_ICON" />
                    <Input
                        v-model="searchQuery"
                        placeholder="Search templates..."
                        :class="FLAT_SEARCH_INPUT"
                    />
                </div>
            </div>

            <div
                v-if="orgTemplates.length === 0 && globalTemplates.length === 0"
                class="rounded-3xl border-2 border-dashed border-gray-100 py-20 text-center dark:border-gray-800/50"
            >
                <p class="font-medium text-gray-400">
                    No AI templates found matching your criteria.
                </p>
            </div>

            <div class="space-y-10">
                <!-- My Organization section -->
                <div v-if="orgItems.length > 0">
                    <div class="mb-4 flex items-center gap-3">
                        <h2
                            class="text-[10px] font-black tracking-[0.2em] text-projector-primary-600 uppercase dark:text-projector-primary-400"
                        >
                            My Organization
                        </h2>
                        <span
                            class="text-[9px] font-black text-gray-400 dark:text-gray-600"
                            >{{ orgTemplates.length }}</span
                        >
                    </div>
                    <ResourceList :items="orgItems">
                        <template #default="{ item }">
                            <ResourceHeader
                                v-if="item.isHeader"
                                :title="item.name"
                                :count="item.count"
                                :collapsed="false"
                            />
                            <FlatRow
                                v-else
                                height="md"
                                clickable
                                @click="handleShow(item.id)"
                            >
                                <span
                                    class="truncate text-[13px] font-bold text-slate-900 dark:text-slate-100"
                                    >{{ item.name }}</span
                                >

                                <template #actions>
                                    <button
                                        type="button"
                                        @click.stop="handleShow(item.id)"
                                        :class="FLAT_ACTION_BUTTON"
                                        title="Edit template"
                                    >
                                        <Edit2 class="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        v-if="canCreate"
                                        type="button"
                                        @click.stop="handleCopy(item.id)"
                                        :class="FLAT_ACTION_BUTTON"
                                        title="Duplicate template"
                                    >
                                        <Copy class="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        v-if="item.can_edit"
                                        type="button"
                                        @click.stop="
                                            openModal({
                                                id: item.id,
                                                name: item.name,
                                            })
                                        "
                                        class="flex h-7 w-7 items-center justify-center rounded-md text-slate-300 opacity-0 transition-colors group-hover:opacity-100 hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-950/30"
                                        title="Delete template"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                    </button>
                                </template>
                            </FlatRow>
                        </template>
                    </ResourceList>
                </div>

                <!-- Global Templates section -->
                <div v-if="globalItems.length > 0">
                    <div class="mb-4 flex items-center gap-3">
                        <h2
                            class="text-[10px] font-black tracking-[0.2em] text-gray-400 uppercase dark:text-gray-500"
                        >
                            Global Templates
                        </h2>
                        <span
                            class="text-[9px] font-black text-gray-400 dark:text-gray-600"
                            >{{ globalTemplates.length }}</span
                        >
                    </div>
                    <ResourceList :items="globalItems">
                        <template #default="{ item }">
                            <ResourceHeader
                                v-if="item.isHeader"
                                :title="item.name"
                                :count="item.count"
                                :collapsed="false"
                            />
                            <FlatRow
                                v-else
                                height="md"
                                clickable
                                @click="handleShow(item.id)"
                            >
                                <span
                                    class="truncate text-[13px] font-bold text-slate-900 dark:text-slate-100"
                                    >{{ item.name }}</span
                                >

                                <template #actions>
                                    <button
                                        type="button"
                                        @click.stop="handleShow(item.id)"
                                        :class="FLAT_ACTION_BUTTON"
                                        title="Edit template"
                                    >
                                        <Edit2 class="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        v-if="canCreate"
                                        type="button"
                                        @click.stop="handleCopy(item.id)"
                                        :class="FLAT_ACTION_BUTTON"
                                        title="Duplicate template"
                                    >
                                        <Copy class="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        v-if="item.can_edit"
                                        type="button"
                                        @click.stop="
                                            openModal({
                                                id: item.id,
                                                name: item.name,
                                            })
                                        "
                                        class="flex h-7 w-7 items-center justify-center rounded-md text-slate-300 opacity-0 transition-colors group-hover:opacity-100 hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-950/30"
                                        title="Delete template"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
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

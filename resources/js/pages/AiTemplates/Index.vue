<script setup lang="ts">
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { useConfirmDelete } from '@/composables/useConfirmDelete';
import AppLayout from '@/layouts/AppLayout.vue';
import { FLAT_SEARCH_ICON, FLAT_SEARCH_INPUT } from '@/lib/flat-ui';
import aiTemplateRoutes from '@/routes/transformation-library';
import {
    availability as availabilityRoute,
    duplicate as duplicateRoute,
} from '@/routes/transformation-library/index';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { Copy, Edit2, PlusIcon, Search, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

interface AiTemplateWithPerms extends AiTemplate {
    organization_id: string | null;
    can_edit: boolean;
    can_toggle: boolean;
    is_enabled: boolean;
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

// Optimistic on/off state, by template id — flipped as soon as the switch moves and put back if
// the save fails.
const enabledById = ref<Record<number, boolean>>(
    Object.fromEntries(props.templates.map((t) => [t.id, t.is_enabled])),
);

const isEnabled = (item: AiTemplateWithPerms) =>
    enabledById.value[item.id] ?? item.is_enabled;

const setEnabled = async (item: AiTemplateWithPerms, enabled: boolean) => {
    enabledById.value[item.id] = enabled;
    try {
        await axios.put(availabilityRoute.url(item.id), { enabled });
        toast.success(
            enabled
                ? `${item.name} turned on for your organization`
                : `${item.name} turned off for your organization`,
        );
    } catch {
        enabledById.value[item.id] = !enabled;
        toast.error(`Couldn't turn ${item.name} ${enabled ? 'on' : 'off'}.`);
    }
};

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
// One grid of cards, grouped by type (Workflow, Org Extraction, System) then by name; search
// matches the name or the description.
const filtered = computed(() => {
    const query = searchQuery.value.toLowerCase().trim();
    return [...props.templates]
        .filter(
            (t) =>
                !query ||
                t.name.toLowerCase().includes(query) ||
                (t.description ?? '').toLowerCase().includes(query),
        )
        .sort(
            (a, b) =>
                typeRank(a.type) - typeRank(b.type) ||
                a.name.localeCompare(b.name),
        );
});

// Workflow cards first, with every other type starting on its own row below them.
const sections = computed(() =>
    [
        filtered.value.filter((t) => t.type === 'workflow'),
        filtered.value.filter((t) => t.type !== 'workflow'),
    ].filter((section) => section.length > 0),
);

// Looked up internally by their respective services (TextExtractionService,
// SpreadsheetClassificationService, CreateTaskFromSlackCommand), never picked by hand — the
// backend only ever sends these to a super-admin (see AiTemplateController::index()), so the
// "System" badge only ever shows for that viewer.
const SYSTEM_TEMPLATE_TYPES = [
    'text_extraction',
    'text_extraction_classification',
    'spreadsheet_column_classification',
    'slack_task_extraction',
    'slack_event_extraction',
    'slack_report_request',
];

const typeRank = (type: string): number => {
    if (type === 'workflow') return 0;
    if (type === 'org_extraction') return 1;
    if (SYSTEM_TEMPLATE_TYPES.includes(type)) return 2;
    return 3;
};

const typeLabel = (type: string): string => {
    if (type === 'workflow') return 'Workflow';
    if (type === 'org_extraction') return 'Org Extraction';
    if (SYSTEM_TEMPLATE_TYPES.includes(type)) return 'System';
    return type;
};
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
                        Your Document Transformations
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
                v-if="filtered.length === 0"
                class="rounded-3xl border-2 border-dashed border-gray-100 py-20 text-center dark:border-gray-800/50"
            >
                <p class="font-medium text-gray-400">
                    No AI templates found matching your criteria.
                </p>
            </div>

            <div v-else class="flex flex-col gap-5 md:gap-4">
                <div
                    v-for="(section, index) in sections"
                    :key="index"
                    class="grid gap-5 md:grid-cols-2 md:gap-4 lg:grid-cols-3 xl:grid-cols-4"
                >
                    <div
                        v-for="item in section"
                        :key="item.id"
                        role="button"
                        tabindex="0"
                        class="group flex cursor-pointer flex-col rounded-xl border border-gray-200 bg-white p-6 shadow-sm transition-shadow hover:shadow-md focus-visible:ring-2 focus-visible:ring-projector-primary-500 focus-visible:outline-none md:p-4 dark:border-white/10 dark:bg-white/5"
                        @click="handleShow(item.id)"
                        @keydown.enter="handleShow(item.id)"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <h3
                                class="text-lg font-bold tracking-tight text-gray-900 md:text-base dark:text-white"
                                :class="{ 'opacity-50': !isEnabled(item) }"
                            >
                                {{ item.name }}
                            </h3>
                            <label
                                v-if="item.can_toggle"
                                class="flex shrink-0 cursor-pointer items-center gap-2 text-xs font-medium text-gray-500 dark:text-gray-400"
                                :title="
                                    isEnabled(item)
                                        ? 'Shown to your organization — turn off to hide it'
                                        : 'Hidden from your organization — turn on to show it'
                                "
                                @click.stop
                                @keydown.enter.stop
                            >
                                {{ isEnabled(item) ? 'On' : 'Off' }}
                                <Switch
                                    :model-value="isEnabled(item)"
                                    :aria-label="`Show ${item.name} to your organization`"
                                    @update:model-value="
                                        (enabled: boolean) =>
                                            setEnabled(item, enabled)
                                    "
                                />
                            </label>
                        </div>
                        <p
                            class="mt-1.5 line-clamp-2 min-h-10 text-sm leading-5 text-gray-600 dark:text-gray-400"
                            :class="{ 'opacity-50': !isEnabled(item) }"
                        >
                            {{ item.description }}
                        </p>

                        <div
                            class="mt-auto flex items-center justify-end gap-1.5 pt-4 md:pt-2"
                        >
                            <span
                                v-if="isSuperAdmin && item.organization_id"
                                class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600 dark:bg-white/10 dark:text-slate-300"
                                >Org</span
                            >
                            <span
                                v-if="isSuperAdmin"
                                class="rounded-full bg-projector-primary-100 px-2.5 py-0.5 text-xs font-medium text-projector-primary-700 dark:bg-projector-primary-900/40 dark:text-projector-primary-200"
                                >{{ typeLabel(item.type) }}</span
                            >
                            <button
                                type="button"
                                class="ml-1 flex h-8 w-8 items-center justify-center rounded-md text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-white"
                                :title="
                                    item.can_edit
                                        ? 'Edit template'
                                        : 'View Transformation'
                                "
                                :aria-label="
                                    item.can_edit
                                        ? 'Edit template'
                                        : 'View Transformation'
                                "
                                @click.stop="handleShow(item.id)"
                            >
                                <Edit2 v-if="item.can_edit" class="h-4 w-4" />
                                <Search v-else class="h-4 w-4" />
                            </button>
                            <button
                                v-if="canCreate"
                                type="button"
                                class="flex h-8 w-8 items-center justify-center rounded-md text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-white"
                                title="Duplicate template"
                                @click.stop="handleCopy(item.id)"
                            >
                                <Copy class="h-4 w-4" />
                            </button>
                            <button
                                v-if="item.can_edit"
                                type="button"
                                class="flex h-8 w-8 items-center justify-center rounded-md text-gray-500 transition-colors hover:bg-red-50 hover:text-red-600 dark:text-gray-400 dark:hover:bg-red-950/30"
                                title="Delete template"
                                @click.stop="
                                    openModal({
                                        id: String(item.id),
                                        name: item.name,
                                    })
                                "
                            >
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
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

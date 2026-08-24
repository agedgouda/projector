<script setup lang="ts">
import { evaluateDescription as evaluateDescriptionRoute } from '@/actions/App/Http/Controllers/ProjectController';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import LogoFileInput from '@/components/LogoFileInput.vue';
import LogoUpload from '@/components/LogoUpload.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/composables/usePermissions';
import { KANBAN_COLOR_PALETTE, kanbanDotClasses } from '@/lib/constants';
import ClientEntryForm from '@/pages/Clients/Partials/ClientEntryForm.vue';
import projectRoutes from '@/routes/projects/index';
import projectLogoRoutes from '@/routes/projects/logo/index';
import { router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Loader2,
    MoreVertical,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

interface Props {
    clients?: Client[];
    client?: Client;
    editData?: Project & { logo_url?: string | null };
    initialName?: string;
    parentProject?: { id: string; name: string; client_id: string } | null;
    projects?: {
        id: string;
        name: string;
        client_id: string;
        parent_id?: string | null;
    }[];
}

const props = defineProps<Props>();
const emit = defineEmits<{
    success: [clientId: string];
    cancel: [];
}>();

const isEditing = !!props.editData;
const originalDescription = props.editData?.description ?? '';

const form = useForm({
    name: props.editData?.name || props.initialName || '',
    description: props.editData?.description || '',
    description_quality: null as 'good' | 'vague' | null,
    inactive: props.editData?.inactive ?? false,
    client_id:
        props.editData?.client_id ||
        props.client?.id ||
        props.parentProject?.client_id ||
        '',
    logo: null as File | null,
    parent_id: props.editData?.parent_id || props.parentProject?.id || null,
});

// Only offered when a `projects` candidate list is supplied — same-client, top-level
// projects only, matching the 2-level nesting cap.
const availableParentProjects = computed(() =>
    (props.projects ?? []).filter(
        (p) =>
            p.client_id === form.client_id &&
            !p.parent_id &&
            p.id !== props.editData?.id,
    ),
);

// A project that already has sub-projects can't become a sub-project itself (would
// create a 3rd level) — hide the field entirely in that case rather than let the
// user pick something the backend will reject.
const editingHasChildren = computed(
    () =>
        isEditing &&
        (props.projects ?? []).some((p) => p.parent_id === props.editData?.id),
);

watch(
    () => form.client_id,
    () => {
        if (props.parentProject) return;
        if (
            form.parent_id &&
            !availableParentProjects.value.some((p) => p.id === form.parent_id)
        ) {
            form.parent_id = null;
        }
    },
);

const NO_PARENT_SENTINEL = '__no_parent__';
const handleParentSelect = (value: unknown) => {
    form.parent_id = value === NO_PARENT_SENTINEL ? null : (value as string);
};

const evaluating = ref(false);
const descriptionQuality = ref<'good' | 'vague' | null>(
    isEditing && props.editData?.description_quality === 'vague'
        ? 'vague'
        : null,
);
const descriptionSuggestions = ref<string[]>([]);

watch(
    () => form.description,
    () => {
        descriptionQuality.value = null;
        descriptionSuggestions.value = [];
    },
);

const CREATE_NEW_SENTINEL = '__create_new__';
const showNewClientForm = ref(false);

const handleClientSelect = (value: unknown) => {
    if (!value || value === CREATE_NEW_SENTINEL) {
        showNewClientForm.value = true;
    } else {
        form.client_id = value as string;
    }
};

const handleClientCreated = (clientId: string) => {
    form.client_id = clientId;
    showNewClientForm.value = false;
};

// Category management — edit mode only, since a category needs a real project_id to
// belong to. CategoryController resolves the family root itself (see
// Project::familyRoot()), so every request here just targets whichever project is
// currently being edited — no need to work out the root id on the frontend.
const { hasRole } = usePermissions();
const canManageCategories = computed(
    () =>
        hasRole('super-admin') ||
        hasRole('org-admin') ||
        hasRole('project-lead'),
);

const categories = ref<CategoryDef[]>([]);

const fetchCategories = () => {
    if (!props.editData) return;

    fetch(projectRoutes.categories.index.url({ project: props.editData.id }), {
        headers: { Accept: 'application/json' },
    })
        .then((response) => response.json())
        .then((data: CategoryDef[]) => {
            categories.value = data;
        })
        .catch(() => toast.error('Failed to load tags.'));
};

onMounted(() => {
    if (isEditing) fetchCategories();
});

const isAddingCategory = ref(false);
const newCategoryName = ref('');
const newCategoryColor = ref<string>(KANBAN_COLOR_PALETTE[0]);

const submitAddCategory = () => {
    const name = newCategoryName.value.trim();
    if (!name || !props.editData) {
        isAddingCategory.value = false;
        return;
    }

    router.post(
        projectRoutes.categories.store.url({ project: props.editData.id }),
        { name, color: newCategoryColor.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                fetchCategories();
                isAddingCategory.value = false;
                newCategoryName.value = '';
                newCategoryColor.value = KANBAN_COLOR_PALETTE[0];
            },
            onError: () => toast.error('Failed to add tag.'),
        },
    );
};

const renamingCategoryId = ref<string | null>(null);
const renameCategoryName = ref('');

const startRenameCategory = (category: CategoryDef) => {
    renamingCategoryId.value = category.id;
    renameCategoryName.value = category.name;
};

const submitRenameCategory = (category: CategoryDef) => {
    const name = renameCategoryName.value.trim();
    if (!name || !props.editData || name === category.name) {
        renamingCategoryId.value = null;
        return;
    }

    router.patch(
        projectRoutes.categories.update.url({
            project: props.editData.id,
            category: category.id,
        }),
        { name },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                category.name = name;
                renamingCategoryId.value = null;
            },
            onError: () => toast.error('Failed to rename tag.'),
        },
    );
};

const recolorCategory = (category: CategoryDef, color: string) => {
    if (!props.editData || color === category.color) return;

    router.patch(
        projectRoutes.categories.update.url({
            project: props.editData.id,
            category: category.id,
        }),
        { color },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                category.color = color;
            },
            onError: () => toast.error('Failed to recolor tag.'),
        },
    );
};

const pendingDeleteCategory = ref<CategoryDef | null>(null);
const isDeletingCategory = ref(false);

const executeDeleteCategory = () => {
    if (!props.editData || !pendingDeleteCategory.value) return;
    const category = pendingDeleteCategory.value;

    isDeletingCategory.value = true;

    router.delete(
        projectRoutes.categories.destroy.url({
            project: props.editData.id,
            category: category.id,
        }),
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                categories.value = categories.value.filter(
                    (c) => c.id !== category.id,
                );
                pendingDeleteCategory.value = null;
            },
            onError: () => toast.error('Failed to delete tag.'),
            onFinish: () => {
                isDeletingCategory.value = false;
            },
        },
    );
};

const getCsrfToken = (): string => {
    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

const doSubmit = () => {
    const url = isEditing
        ? projectRoutes.update.url(String(props.editData!.id))
        : projectRoutes.store.url();

    const method = isEditing ? 'patch' : 'post';

    // Carry over the quality verdict from the pre-submission evaluation below, so the
    // "AI-Enhanced" / "too vague" badge is correct immediately — no need to wait on the
    // backend's async fallback job and refresh the page to see it.
    form.description_quality = descriptionQuality.value;

    form[method](url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            toast.success(isEditing ? 'Project Updated' : 'Project Created');
            emit('success', form.client_id);
        },
        onError: () => {
            toast.error('Submission failed', {
                description: 'Please check the required fields.',
            });
        },
    });
};

const submit = async () => {
    const descriptionChanged = form.description !== originalDescription;
    const needsEvaluation =
        form.description &&
        form.client_id &&
        (!isEditing || descriptionChanged) &&
        descriptionQuality.value === null;

    if (!needsEvaluation) {
        doSubmit();
        return;
    }

    evaluating.value = true;

    try {
        const response = await fetch(evaluateDescriptionRoute().url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({
                description: form.description,
                client_id: form.client_id,
            }),
        });

        const data = await response.json();
        descriptionQuality.value = data.quality ?? 'good';
        descriptionSuggestions.value = data.suggestions ?? [];

        if (descriptionQuality.value === 'good') {
            doSubmit();
        }
    } catch {
        doSubmit();
    } finally {
        evaluating.value = false;
    }
};
</script>

<template>
    <form @submit.prevent="submit" class="space-y-6">
        <LogoUpload
            v-if="isEditing && editData"
            :current-logo-url="editData.logo_url ?? null"
            :upload-url="projectLogoRoutes.store.url(String(editData.id))"
            :delete-url="projectLogoRoutes.destroy.url(String(editData.id))"
            label="Project Logo"
        />

        <div class="space-y-5">
            <div v-if="!isEditing" class="grid gap-2">
                <Label
                    class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                >
                    Client
                </Label>
                <div
                    v-if="client"
                    class="flex h-12 items-center rounded-xl border border-gray-200 bg-gray-50 px-4 text-sm font-bold text-projector-primary-600 dark:border-zinc-800 dark:bg-zinc-800/50"
                >
                    {{ client.company_name }}
                </div>
                <template v-else-if="showNewClientForm">
                    <div
                        class="rounded-xl border border-projector-primary-200 bg-projector-primary-50/50 p-4 dark:border-projector-primary-800 dark:bg-projector-primary-950/30"
                    >
                        <p
                            class="mb-3 text-[10px] font-black tracking-widest text-projector-primary-500 uppercase"
                        >
                            New Client
                        </p>
                        <ClientEntryForm
                            :edit-data="null"
                            @success="handleClientCreated"
                            @clear-edit="showNewClientForm = false"
                        />
                    </div>
                </template>
                <Select
                    v-else
                    :model-value="form.client_id"
                    @update:model-value="handleClientSelect"
                >
                    <SelectTrigger
                        class="h-12 rounded-xl font-bold"
                        :class="
                            form.errors.client_id
                                ? 'border-red-500'
                                : 'border-gray-200 dark:border-gray-800'
                        "
                    >
                        <SelectValue placeholder="Select a client..." />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            :value="CREATE_NEW_SENTINEL"
                            class="font-bold text-projector-primary-600"
                        >
                            + Create New Client
                        </SelectItem>
                        <SelectSeparator v-if="clients?.length" />
                        <SelectItem
                            v-for="c in clients"
                            :key="c.id"
                            :value="c.id.toString()"
                        >
                            {{ c.company_name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p
                    v-if="form.errors.client_id"
                    class="px-1 text-[10px] font-bold tracking-tight text-red-500 uppercase"
                >
                    {{ form.errors.client_id }}
                </p>
            </div>

            <div v-if="!isEditing && parentProject" class="grid gap-2">
                <Label
                    class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                >
                    Sub-project of
                </Label>
                <div
                    class="flex h-12 items-center rounded-xl border border-gray-200 bg-gray-50 px-4 text-sm font-bold text-projector-primary-600 dark:border-zinc-800 dark:bg-zinc-800/50"
                >
                    {{ parentProject.name }}
                </div>
            </div>

            <div
                v-else-if="!editingHasChildren && projects?.length"
                class="grid gap-2"
            >
                <Label
                    class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                >
                    Parent Project (optional)
                </Label>
                <Select
                    :model-value="form.parent_id ?? NO_PARENT_SENTINEL"
                    @update:model-value="handleParentSelect"
                >
                    <SelectTrigger
                        class="h-12 rounded-xl border-gray-200 font-bold dark:border-gray-800"
                    >
                        <SelectValue placeholder="None" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="NO_PARENT_SENTINEL"
                            >None</SelectItem
                        >
                        <SelectSeparator
                            v-if="availableParentProjects.length"
                        />
                        <SelectItem
                            v-for="p in availableParentProjects"
                            :key="p.id"
                            :value="p.id"
                        >
                            {{ p.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p
                    v-if="form.errors.parent_id"
                    class="px-1 text-[10px] font-bold tracking-tight text-red-500 uppercase"
                >
                    {{ form.errors.parent_id }}
                </p>
            </div>

            <p
                v-else-if="editingHasChildren"
                class="px-1 text-[11px] text-slate-400"
            >
                This project has sub-projects, so it can't become one itself.
            </p>

            <div class="grid gap-2">
                <Label
                    for="name"
                    class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                >
                    Project Name
                </Label>
                <Input
                    id="name"
                    v-model="form.name"
                    class="h-12 rounded-xl bg-white font-bold dark:bg-gray-950"
                    :class="
                        form.errors.name
                            ? 'border-red-500'
                            : 'border-gray-200 dark:border-gray-800'
                    "
                />
                <p
                    v-if="form.errors.name"
                    class="px-1 text-[10px] font-bold tracking-tight text-red-500 uppercase"
                >
                    {{ form.errors.name }}
                </p>
            </div>

            <div class="grid gap-2">
                <Label
                    for="description"
                    class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                >
                    Description / Scope
                </Label>
                <Textarea
                    id="description"
                    v-model="form.description"
                    class="min-h-[100px] resize-none rounded-xl border-gray-200 bg-white font-medium dark:border-gray-800 dark:bg-gray-950"
                    :class="{
                        'border-amber-400 dark:border-amber-500':
                            descriptionQuality === 'vague',
                    }"
                />
                <div v-if="evaluating" class="flex items-center gap-2 px-1">
                    <Loader2
                        class="h-3 w-3 animate-spin text-projector-primary-400"
                    />
                    <span
                        class="text-[10px] font-bold tracking-widest text-projector-primary-400 uppercase"
                        >Analyzing description...</span
                    >
                </div>
                <div
                    v-else-if="descriptionQuality === 'vague'"
                    class="space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/30"
                >
                    <div class="flex items-center gap-2">
                        <AlertTriangle
                            class="h-3.5 w-3.5 shrink-0 text-amber-500"
                        />
                        <span
                            class="text-[11px] font-bold tracking-widest text-amber-700 uppercase dark:text-amber-400"
                            >Description needs more detail</span
                        >
                    </div>
                    <ul
                        v-if="descriptionSuggestions.length"
                        class="space-y-1 pl-1"
                    >
                        <li
                            v-for="(suggestion, i) in descriptionSuggestions"
                            :key="i"
                            class="flex items-start gap-1.5 text-[11px] text-amber-700 dark:text-amber-300"
                        >
                            <span class="mt-0.5 shrink-0 text-amber-400"
                                >›</span
                            >
                            {{ suggestion }}
                        </li>
                    </ul>
                    <p
                        v-else
                        class="pl-1 text-[11px] text-amber-600 dark:text-amber-400"
                    >
                        Consider adding more detail about the project's purpose,
                        audience, or goals.
                    </p>
                </div>
            </div>

            <div v-if="isEditing" class="flex items-center gap-2 pt-2">
                <input
                    id="project-inactive"
                    v-model="form.inactive"
                    type="checkbox"
                    class="h-4 w-4 rounded border-gray-300 text-projector-primary-600 focus:ring-projector-primary-500"
                />
                <Label
                    for="project-inactive"
                    class="cursor-pointer px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                >
                    Inactive
                </Label>
            </div>

            <div v-if="isEditing" class="grid gap-2">
                <div class="flex items-center justify-between px-1">
                    <Label
                        class="text-[10px] font-black tracking-widest text-gray-400 uppercase"
                    >
                        Tags
                    </Label>
                    <Popover
                        v-if="canManageCategories"
                        v-model:open="isAddingCategory"
                    >
                        <PopoverTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="h-6 w-6 text-gray-400 hover:text-projector-primary-600"
                            >
                                <Plus class="h-4 w-4" />
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent class="w-64 space-y-3 p-3" align="end">
                            <Input
                                v-model="newCategoryName"
                                placeholder="Tag name"
                                autofocus
                                class="h-8 text-xs"
                                @keydown.enter="submitAddCategory"
                            />
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="color in KANBAN_COLOR_PALETTE"
                                    :key="color"
                                    type="button"
                                    :class="[
                                        'h-5 w-5 rounded-full',
                                        kanbanDotClasses[color],
                                        newCategoryColor === color
                                            ? 'ring-2 ring-projector-primary-500 ring-offset-1'
                                            : '',
                                    ]"
                                    @click="newCategoryColor = color"
                                />
                            </div>
                            <Button
                                type="button"
                                size="sm"
                                class="h-8 w-full px-3"
                                @click="submitAddCategory"
                                >Add</Button
                            >
                        </PopoverContent>
                    </Popover>
                </div>

                <p
                    v-if="!categories.length"
                    class="px-1 text-[11px] text-slate-400"
                >
                    No tags yet.
                </p>

                <div
                    v-for="category in categories"
                    :key="category.id"
                    class="group/category flex h-9 items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 dark:border-zinc-800 dark:bg-zinc-800/50"
                >
                    <Popover v-if="canManageCategories">
                        <PopoverTrigger as-child>
                            <button
                                type="button"
                                :class="[
                                    'h-2.5 w-2.5 shrink-0 rounded-full',
                                    kanbanDotClasses[category.color],
                                ]"
                            />
                        </PopoverTrigger>
                        <PopoverContent class="w-auto p-2" align="start">
                            <div class="flex max-w-[140px] flex-wrap gap-1.5">
                                <button
                                    v-for="color in KANBAN_COLOR_PALETTE"
                                    :key="color"
                                    type="button"
                                    :class="[
                                        'h-5 w-5 rounded-full',
                                        kanbanDotClasses[color],
                                        category.color === color
                                            ? 'ring-2 ring-projector-primary-500 ring-offset-1'
                                            : '',
                                    ]"
                                    @click="recolorCategory(category, color)"
                                />
                            </div>
                        </PopoverContent>
                    </Popover>
                    <div
                        v-else
                        :class="[
                            'h-2.5 w-2.5 shrink-0 rounded-full',
                            kanbanDotClasses[category.color],
                        ]"
                    />

                    <Input
                        v-if="renamingCategoryId === category.id"
                        v-model="renameCategoryName"
                        autofocus
                        class="h-6 flex-1 text-xs"
                        @keydown.enter="submitRenameCategory(category)"
                        @keydown.escape="renamingCategoryId = null"
                        @blur="submitRenameCategory(category)"
                    />
                    <span
                        v-else
                        class="flex-1 text-xs font-bold text-gray-700 dark:text-gray-200"
                        >{{ category.name }}</span
                    >

                    <DropdownMenu v-if="canManageCategories">
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="h-5 w-5 opacity-0 transition-opacity group-hover/category:opacity-100"
                            >
                                <MoreVertical class="h-3 w-3 text-gray-400" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-36">
                            <DropdownMenuItem
                                @click="startRenameCategory(category)"
                            >
                                <Pencil class="mr-2 h-3.5 w-3.5" />
                                Rename
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                @click="pendingDeleteCategory = category"
                                class="text-red-600 focus:text-red-600"
                            >
                                <Trash2 class="mr-2 h-3.5 w-3.5" />
                                Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                <ConfirmDeleteModal
                    :open="!!pendingDeleteCategory"
                    :title="`Delete '${pendingDeleteCategory?.name}' Tag`"
                    description="This can't be undone. Any documents using this tag will simply have it removed."
                    :loading="isDeletingCategory"
                    @close="pendingDeleteCategory = null"
                    @confirm="executeDeleteCategory"
                />
            </div>

            <LogoFileInput
                v-if="!isEditing"
                v-model="form.logo"
                label="Project Logo"
                :error="form.errors.logo"
            />
        </div>

        <div
            class="flex items-center justify-end gap-3 border-t border-gray-100 pt-6 dark:border-gray-800"
        >
            <Button
                type="button"
                @click="emit('cancel')"
                class="h-12 w-28 rounded-xl border border-projector-primary-600 bg-white text-[10px] font-black tracking-widest text-projector-primary-600 uppercase hover:bg-projector-primary-50 dark:border-projector-primary-400 dark:bg-transparent dark:text-projector-primary-400 dark:hover:bg-projector-primary-950/30"
            >
                Cancel
            </Button>

            <template v-if="descriptionQuality === 'vague'">
                <Button
                    type="button"
                    variant="outline"
                    @click="descriptionQuality = null"
                    class="border-gray-200 text-[10px] font-black tracking-widest uppercase dark:border-gray-700"
                >
                    Edit Description
                </Button>
                <Button
                    type="button"
                    :disabled="form.processing"
                    @click="doSubmit"
                    class="h-12 rounded-xl bg-amber-500 px-8 text-[10px] font-black tracking-widest text-white uppercase shadow-lg hover:bg-amber-600"
                >
                    Save Anyway
                </Button>
            </template>

            <Button
                v-else
                type="submit"
                :disabled="form.processing || evaluating"
                class="h-12 w-28 rounded-xl bg-projector-primary-600 text-[10px] font-black tracking-widest text-white uppercase shadow-lg hover:bg-projector-primary-700"
            >
                <Loader2 v-if="evaluating" class="h-4 w-4 animate-spin" />
                <template v-else>{{
                    isEditing ? 'Save Changes' : 'Save'
                }}</template>
            </Button>
        </div>
    </form>
</template>

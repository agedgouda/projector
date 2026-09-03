<script setup lang="ts">
import CommentSection from '@/components/comments/CommentSection.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useDocumentEditor } from '@/composables/useDocumentEditor';
import { mergeAssigneeOptions, mergeMentionableUsers } from '@/lib/assignees';
import {
    PRIORITY_LABELS,
    kanbanDotClasses,
    priorityDotClasses,
} from '@/lib/constants';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import { router, usePage } from '@inertiajs/vue3';
import { EditorContent } from '@tiptap/vue-3';
import axios from 'axios';
import {
    Bold,
    Clock,
    Italic,
    List,
    ListOrdered,
    Paperclip,
    Plus,
    Trash2,
} from 'lucide-vue-next';
import { computed, nextTick, reactive, ref, useTemplateRef, watch } from 'vue';
import { toast } from 'vue-sonner';

const props = withDefaults(
    defineProps<{
        open: boolean;
        // Required in 'edit' mode (an existing document to show); absent in 'create' mode, where
        // this sheet instead renders a draft form and posts a brand-new one on submit.
        document?: UIProjectDocument;
        reprocessableTypes: Set<string>;
        aiProcessedParentIds: Set<string>;
        mode?: 'edit' | 'create';
        // Only meaningful in 'create' mode — there's no document.project_id yet to resolve it
        // from.
        projectId?: string;
    }>(),
    {
        mode: 'edit',
    },
);

const emit = defineEmits<{
    // Standard V-Model for the Sheet
    (e: 'update:open', val: boolean): void;

    // Your custom attribute update funnel
    (e: 'update-attribute', field: string, value: string | number | null): void;

    // A task was created (create mode only) — the caller patches it into whatever local
    // list/board is showing rather than reloading.
    (e: 'created', document: UIProjectDocument): void;

    // The new reprocessing action
    (e: 'handleReprocess', id: string | number): void;

    // Run a user-chosen protocol- or AI-template-driven transition
    (
        e: 'handleTransition',
        id: string | number,
        payload: {
            toKey?: string;
            aiTemplateId: number;
            singleOutput?: boolean;
            projectTypeId?: string;
        },
    ): void;

    // Full desired tag list (sync semantics), matching useKanbanActions'/useDocumentActions'
    // own updateTags(id, categories) signature — not a single add/remove.
    (e: 'update-tags', id: string | number, categories: CategoryDef[]): void;

    // A comment was posted or deleted. `document.comments` isn't always a live Inertia prop
    // (see CommentSection.vue's own `changed` emit), so the caller needs to know to re-fetch it.
    (e: 'comments-changed', id: string | number): void;

    // The title was saved (see saveName() below — it POSTs directly to
    // DocumentController::update() rather than through the update-attribute funnel), so the
    // Kanban card / reports row showing this same document elsewhere on screen won't pick up
    // the new name on their own — the caller needs to patch its own copy in place.
    (e: 'name-updated', id: string | number, name: string): void;
}>();
const page = usePage<AppPageProps>();

const currentProject = computed(
    () => (page.props as any).currentProject as Project | null,
);

// currentProject is only set on the single-project page — on the Dashboard, tasks from
// many projects share this same sheet, so resolve the owning project (and its columns)
// from the document itself instead. In create mode there's no document yet, so `projectId`
// is the only source — same lookup either way, just a different id source.
const allProjects = computed(
    () => (page.props as any).projects as Project[] | undefined,
);
const targetProjectId = computed(() =>
    props.mode === 'create' ? props.projectId : props.document?.project_id,
);
const documentProject = computed(
    () =>
        allProjects.value?.find((p) => p.id === targetProjectId.value) ??
        currentProject.value,
);
const columns = computed(() => documentProject.value?.kanban_columns ?? []);

// Draft state for create mode — mirrors Documents/Create.vue's own form fields/defaults.
// Staged locally and only sent to the server once "Create Task" is clicked, unlike edit
// mode's per-field autosave (`handleUpdate` below writes here instead of emitting
// `update-attribute` when `mode === 'create'`).
const draft = reactive({
    name: '',
    content: '',
    assignee_id: 'unassigned' as string,
    due_at: '' as string,
    start_at: '' as string,
    external_due_at: '' as string,
    priority: 'low',
    task_status: 'todo',
    custom_prompt: '' as string,
    category_ids: [] as string[],
});

// Resolves a field's current displayed value from the draft in create mode, or from the
// document in edit mode — lets the Assignee/Due Date/Status/Priority section below share one
// template between both modes instead of duplicating it.
const fieldValue = (field: keyof typeof draft, fallback: unknown = null) => {
    if (props.mode === 'create') return draft[field];
    return (
        (props.document as unknown as Record<string, unknown> | undefined)?.[
            field
        ] ?? fallback
    );
};

const currentColumn = computed(() =>
    columns.value.find(
        (c) => c.key === (fieldValue('task_status', 'todo') as string),
    ),
);

const usesExternalDueDates = computed(
    () => (page.props as any).orgMembership?.uses_external_due_dates ?? false,
);

// Same merge as every other assignee picker in the app (DocumentSidebar.vue, TaskReportTable.vue)
// — matches on a real user id or an `inv:`-prefixed pending-invitation id.
const assigneeValue = computed(() => {
    if (props.mode === 'create') return draft.assignee_id;
    if (props.document?.pending_assignee_invitation_id)
        return `inv:${props.document.pending_assignee_invitation_id}`;
    return props.document?.assignee_id?.toString() ?? 'unassigned';
});
const assigneeOptions = computed(() =>
    mergeAssigneeOptions(
        documentProject.value?.client?.organization?.users,
        documentProject.value?.client?.organization?.invitations,
    ),
);
const assigneeLabel = computed(() => {
    if (props.mode === 'create') {
        if (assigneeValue.value === 'unassigned') return 'Unassigned';
        return (
            assigneeOptions.value.find((o) => o.value === assigneeValue.value)
                ?.label ?? 'Unassigned'
        );
    }
    if (props.document?.assignee) return props.document.assignee.name;
    if (props.document?.pending_assignee) {
        return (
            assigneeOptions.value.find((o) => o.value === assigneeValue.value)
                ?.label ?? 'Pending'
        );
    }
    return 'Unassigned';
});

const mentionableUsers = computed(() =>
    mergeMentionableUsers(
        documentProject.value?.client?.organization?.users,
        documentProject.value?.client?.organization?.invitations,
    ),
);

// The task family's full tag catalog (see Project::familyCategories()) — tags already
// applied to this task never show up again as an "add" option. In create mode, "applied"
// means staged in `draft.category_ids` rather than persisted on a document.
const appliedCategories = computed(() => {
    if (props.mode === 'create') {
        return (documentProject.value?.categories ?? []).filter((c) =>
            draft.category_ids.includes(c.id),
        );
    }
    return props.document?.categories ?? [];
});
const availableTagsToAdd = computed(() => {
    const appliedIds = new Set(appliedCategories.value.map((c) => c.id));
    return (documentProject.value?.categories ?? []).filter(
        (c) => !appliedIds.has(c.id),
    );
});
const addTag = (category: CategoryDef) => {
    if (props.mode === 'create') {
        draft.category_ids = [...draft.category_ids, category.id];
        return;
    }
    emit('update-tags', props.document!.id, [
        ...(props.document!.categories ?? []),
        category,
    ]);
};
const removeTag = (category: CategoryDef) => {
    if (props.mode === 'create') {
        draft.category_ids = draft.category_ids.filter(
            (id) => id !== category.id,
        );
        return;
    }
    emit(
        'update-tags',
        props.document!.id,
        (props.document!.categories ?? []).filter((c) => c.id !== category.id),
    );
};

// Staged locally rather than emitted straight through on every keystroke (contrast
// InlineDocumentForm.vue's editor, which writes through to its Inertia form field
// immediately) — this sheet's content is always live/persisted data, not a draft form, so
// edits shouldn't reach the server until Save is actually clicked. `savedContent` is the
// last-known-persisted value, used instead of `document.content` itself for dirty-tracking —
// see saveContent()'s own comment for why.
const savedContent = ref(props.document?.content ?? '');
const draftContent = ref(props.document?.content ?? '');
const isContentDirty = computed(
    () => draftContent.value !== savedContent.value,
);

// Same useDocumentEditor composable as every other rich-text field in the app (comments,
// document create/edit forms) — configuring Tiptap in exactly one place means a change
// there (extensions, upload handling, mention behavior) reaches this sheet too. In create
// mode there's no staged/saved split (see saveContent()'s comment below) — the editor writes
// straight into `draft.content`, submitted whole once "Create Task" is clicked.
const { editor, triggerUpload } = useDocumentEditor(
    () => (props.mode === 'create' ? draft.content : props.document?.content),
    (html) => {
        if (props.mode === 'create') {
            draft.content = html;
            if (errors.content) errors.content = undefined;
        } else {
            draftContent.value = html;
        }
    },
    mentionableUsers,
    // Getter, not a snapshot — this sheet is reused across many different documents (and,
    // on the Dashboard, documents from different projects) without remounting, so uploads
    // need to target whichever document is actually showing right now.
    () => documentProject.value?.id,
);

// Resets the draft (and its saved baseline) when a different document is opened in this
// same (reused) sheet — not when *this* component's own save changes `content` server-side,
// since saveContent() below deliberately never touches `document.content` itself.
watch(
    () => props.document?.content,
    (val) => {
        savedContent.value = val ?? '';
        draftContent.value = val ?? '';
    },
);

// Deliberately not routed through the `update-attribute` emit (unlike every other field in
// this sheet) — that funnel ultimately hits DocumentController::updateAttributes(), whose
// validation only accepts task_status/priority/due_at/assignee_id, so a `content` key sent
// there is silently dropped (the request still returns 200, but nothing is persisted).
// updateAttributes() is also gated by the looser "any org member" policy check rather than
// full project-edit access, which content edits should require — the same authorization
// InlineDocumentForm.vue's edit flow already goes through. So this saves directly against
// DocumentController::update() instead, matching that existing content-editing path.
//
// Uses axios directly rather than Inertia's router.patch() — mirroring
// useDocumentActions.ts's updateDocument() — specifically to avoid a full-page Inertia visit:
// this sheet is reused across the Kanban board, the Dashboard, and the Reports table, and in
// every one of those a full visit's fresh props briefly replace the local document/task state
// this sheet's `document` prop is sourced from, which was closing the sheet out from under
// the user right after a successful save. Since nothing else on screen shows document content
// (no Kanban card preview, etc.), there's nothing that actually needs that round trip — this
// component already knows exactly what it just saved.
const isSavingContent = ref(false);

const saveContent = async () => {
    if (!documentProject.value) {
        return;
    }

    isSavingContent.value = true;
    try {
        await axios.post(
            projectDocumentsRoutes.update.url({
                project: documentProject.value.id,
                document: String(props.document!.id),
            }),
            { content: draftContent.value, _method: 'put' },
        );
        savedContent.value = draftContent.value;
        toast.success('Changes saved');
    } catch {
        toast.error('Could not save changes.');
    } finally {
        isSavingContent.value = false;
    }
};

const cancelContentEdit = () => {
    draftContent.value = savedContent.value;
    editor.value?.commands.setContent(savedContent.value, {
        emitUpdate: false,
    });
};

// Same reasoning as content above: `name` isn't in updateAttributes()'s validated field
// list either, so this also saves directly against DocumentController::update() rather than
// through the update-attribute emit, and — same as content — the sheet stays open since
// there's no Inertia visit to replace the props it's reading from out from under it.
// Displayed name when not editing. Mirrors savedContent above: the raw `document.name` prop
// never reflects a save made through this bypass path (no Inertia visit refreshes it), so the
// read-only span would revert to the stale prop value right after a successful save without
// this local copy.
const savedName = ref(props.document?.name ?? '');
const isEditingName = ref(false);
const draftName = ref(props.document?.name ?? '');
const isSavingName = ref(false);
const nameInput = useTemplateRef('nameInput');
const createNameInput = useTemplateRef('createNameInput');
// True when saving would be a no-op (untouched, or trimmed down to nothing) — drives the
// Save button's disabled state, same pattern as isContentDirty above but inverted.
const isNameUnchanged = computed(
    () => draftName.value.trim() === savedName.value || !draftName.value.trim(),
);

// Same "different document opened in this reused sheet" case as content's own watcher above
// — guarded so it never clobbers an edit actually in progress.
watch(
    () => props.document?.name,
    (val) => {
        if (val === undefined) return;
        savedName.value = val;
        if (!isEditingName.value) draftName.value = val;
    },
);

// A <textarea> (not <input>) so a long title wraps instead of silently scrolling the caret
// out of view — a single-line input left the start of a long title unreadable while editing.
// Auto-grows to fit its content since it's standing in for a heading, not a fixed-size field.
// Shared by both the edit-mode name input and the create-mode name textarea below.
const resizeTextareaEl = (el: HTMLTextAreaElement | null) => {
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
};
const resizeNameInput = () => resizeTextareaEl(nameInput.value);
const resizeCreateNameInput = () => resizeTextareaEl(createNameInput.value);

const startEditingName = async () => {
    draftName.value = savedName.value;
    isEditingName.value = true;
    await nextTick();
    nameInput.value?.focus();
    nameInput.value?.select();
    resizeNameInput();
};

const cancelEditingName = () => {
    draftName.value = savedName.value;
    isEditingName.value = false;
};

const saveName = async () => {
    if (isSavingName.value) {
        return;
    }

    // Pressing Enter with nothing changed isn't an error — just close the input, same as Cancel.
    if (isNameUnchanged.value) {
        isEditingName.value = false;
        return;
    }

    const trimmed = draftName.value.trim();

    if (!documentProject.value) {
        return;
    }

    isSavingName.value = true;
    try {
        await axios.post(
            projectDocumentsRoutes.update.url({
                project: documentProject.value.id,
                document: String(props.document!.id),
            }),
            { name: trimmed, _method: 'put' },
        );
        savedName.value = trimmed;
        emit('name-updated', props.document!.id, trimmed);
        toast.success('Changes saved');
        isEditingName.value = false;
    } catch {
        toast.error('Could not save changes.');
    } finally {
        isSavingName.value = false;
    }
};

// Delete — same ConfirmDeleteModal + confirmDeletion() shape as Documents/Show.vue's own
// delete flow (see useDocumentForm.ts), including using a real Inertia router.delete() rather
// than the axios bypass every other edit-mode action in this file uses: unlike an edit, a
// deleted task shouldn't stay visible anywhere, so the normal fresh-props reload this triggers
// is exactly what's wanted — it's what makes the task disappear from the Kanban board/report
// underneath (and, since documentsById/selectedDocument are both derived from that same
// localKanbanData, is also what closes this sheet on its own, no extra event needed).
const isDeleting = ref(false);
const isDeleteModalOpen = ref(false);

const confirmDeletion = () => {
    if (!documentProject.value) return;

    isDeleting.value = true;
    const url = projectDocumentsRoutes.destroy({
        project: documentProject.value.id,
        document: String(props.document!.id),
    }).url;

    router.delete(url, {
        onSuccess: () => {
            toast.success('Document deleted');
        },
        onFinish: () => {
            isDeleting.value = false;
            isDeleteModalOpen.value = false;
        },
    });
};

// Create mode: posts the whole staged draft at once via plain axios (not an Inertia visit —
// see DocumentController::store()'s own X-Inertia check) so the caller gets the created
// document back to patch into local state instead of a full-page redirect/reload.
const isCreatingTask = ref(false);

// Below-field validation messages — same "text-[10px] font-bold text-red-500 uppercase"
// pattern InlineDocumentForm.vue uses for this same name/content pair (see
// Documents/Create.vue), rather than a toast, so a required field left empty reads the same
// way here as it does on the full-page create form. Keyed generically (not just
// name/content) so a 422 response's other field errors (a rare case — Select/date inputs
// always produce a valid value) surface the same way instead of silently falling back to
// nothing.
const errors = reactive<Record<string, string | undefined>>({});

// content is required server-side, but Tiptap's own "nothing typed" output is `<p></p>` —
// non-empty as a string, so a plain trim() wouldn't catch it. Stripping tags first is what
// makes an untouched or fully-cleared editor register as empty here.
const isContentEmpty = (html: string) =>
    html.replace(/<[^>]*>/g, '').trim().length === 0;

watch(
    () => draft.name,
    () => {
        if (errors.name) errors.name = undefined;
    },
);

const createTask = async () => {
    if (props.mode !== 'create' || !props.projectId || isCreatingTask.value) {
        return;
    }

    const trimmedName = draft.name.trim();
    errors.name = trimmedName ? undefined : 'Task name is required.';
    errors.content = isContentEmpty(draft.content)
        ? 'Content is required.'
        : undefined;

    if (errors.name || errors.content) {
        return;
    }

    isCreatingTask.value = true;
    try {
        const response = await axios.post(
            projectDocumentsRoutes.store.url({ project: props.projectId }),
            {
                name: trimmedName,
                type: 'task',
                content: draft.content,
                priority: draft.priority,
                task_status: draft.task_status,
                due_at: draft.due_at || null,
                start_at: draft.start_at || null,
                external_due_at: draft.external_due_at || null,
                assignee_id: draft.assignee_id,
                custom_prompt: draft.custom_prompt || null,
                category_ids: draft.category_ids,
            },
        );
        toast.success('Task created');
        emit('created', response.data);
        emit('update:open', false);
    } catch (err) {
        if (axios.isAxiosError(err) && err.response?.status === 422) {
            const serverErrors = (err.response.data?.errors ?? {}) as Record<
                string,
                string[] | string
            >;
            Object.entries(serverErrors).forEach(([field, messages]) => {
                errors[field] = Array.isArray(messages)
                    ? messages[0]
                    : messages;
            });
        } else {
            toast.error('Could not create the task.');
        }
    } finally {
        isCreatingTask.value = false;
    }
};

const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};

// `document.type` is a raw catalog key (e.g. "task", "action_items"), not already a display
// label — used only for the delete confirmation's title ("Delete Task", not "Delete
// action_items").
const documentTypeLabel = computed(() =>
    (props.document?.type ?? '')
        .split('_')
        .filter(Boolean)
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' '),
);

/**
 * Handle updates with type-casting.
 * We use 'any' for value here to accept the broad 'AcceptableValue' type
 * coming from the Select components.
 */
const handleUpdate = (field: string, value: any) => {
    // Create mode has no document to PATCH yet — every field just stages into `draft`,
    // submitted whole by createTask() above. Kept as the raw Select/input value (not
    // parsed/nulled the way the edit-mode branch below does) since DocumentController::store()
    // (via StoreDocumentRequest::prepareForValidation()) already accepts assignee_id in this
    // same raw "unassigned" / numeric-id / "inv:{id}" shape.
    if (props.mode === 'create') {
        (draft as unknown as Record<string, unknown>)[field] = value;
        return;
    }

    let finalValue = value;

    if (field === 'assignee_id') {
        finalValue =
            value === 'unassigned' || value === null
                ? null
                : parseInt(value, 10);
    }

    // Ensure that if a date is cleared, we send null, otherwise send the string
    if (field === 'due_at' || field === 'external_due_at') {
        finalValue = value === '' ? null : value;
    }

    emit('update-attribute', field, finalValue);
};
</script>

<template>
    <Sheet :open="open" @update:open="(val) => emit('update:open', val)">
        <SheetContent
            class="overflow-y-auto border-l border-gray-100 bg-white pr-12 pl-12 shadow-2xl sm:max-w-[720px] dark:border-gray-800 dark:bg-[hsl(222_47%_6%)] dark:text-white"
        >
            <Button
                v-if="mode !== 'create' && document"
                type="button"
                variant="ghost"
                size="icon"
                class="absolute top-4 right-12 flex h-8 w-8 items-center justify-center p-0 text-slate-400 hover:bg-red-50 hover:text-red-600"
                @click="isDeleteModalOpen = true"
            >
                <Trash2 class="h-4 w-4" />
            </Button>

            <template v-if="document || mode === 'create'">
                <div class="mt-8 space-y-10">
                    <SheetHeader class="space-y-0.5 p-0 text-left">
                        <template v-if="mode === 'create'">
                            <SheetTitle
                                class="mt-5 border-b-2 border-gray-200 pb-1 text-xl leading-tight font-bold text-gray-900 dark:border-gray-700 dark:text-white"
                            >
                                <textarea
                                    ref="createNameInput"
                                    v-model="draft.name"
                                    rows="1"
                                    placeholder="Task name"
                                    class="w-full resize-none overflow-hidden bg-transparent text-xl leading-tight font-bold text-gray-900 placeholder:text-gray-400 focus:outline-none dark:text-white"
                                    @input="resizeCreateNameInput"
                                />
                            </SheetTitle>
                            <p
                                v-if="errors.name"
                                class="text-[10px] font-bold text-red-500 uppercase"
                            >
                                {{ errors.name }}
                            </p>
                        </template>
                        <template v-else>
                            <SheetTitle
                                class="mt-5 border-b-2 pb-1 text-xl leading-tight font-bold text-gray-900 dark:text-white"
                                :class="
                                    isEditingName
                                        ? 'border-projector-primary-500'
                                        : 'border-gray-200 dark:border-gray-700'
                                "
                            >
                                <textarea
                                    v-if="isEditingName"
                                    ref="nameInput"
                                    v-model="draftName"
                                    rows="1"
                                    :disabled="isSavingName"
                                    class="w-full resize-none overflow-hidden bg-transparent text-xl leading-tight font-bold text-gray-900 focus:outline-none dark:text-white"
                                    @input="resizeNameInput"
                                    @keydown.enter.prevent="saveName"
                                    @keydown.escape.prevent="cancelEditingName"
                                />
                                <span
                                    v-else
                                    class="cursor-pointer rounded transition-colors hover:bg-gray-100 dark:hover:bg-white/5"
                                    @click="startEditingName"
                                    >{{ savedName }}</span
                                >
                            </SheetTitle>
                            <div
                                v-if="isEditingName"
                                class="flex justify-end gap-2"
                            >
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="isSavingName"
                                    class="h-8 text-[10px] font-black tracking-widest uppercase"
                                    @click="cancelEditingName"
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    :disabled="isNameUnchanged || isSavingName"
                                    class="h-8 bg-projector-primary-600 text-[10px] font-black tracking-widest uppercase hover:bg-projector-primary-700"
                                    @click="saveName"
                                >
                                    {{ isSavingName ? 'Saving…' : 'Save' }}
                                </Button>
                            </div>
                            <div
                                v-else
                                class="flex items-center justify-between text-[9px] font-black tracking-widest text-gray-400 uppercase"
                            >
                                <span class="flex items-center gap-1.5">
                                    <Clock class="h-3 w-3" /> Updated
                                    {{ formatDate(document!.updated_at) }}
                                </span>
                                <span
                                    class="cursor-pointer"
                                    @click="startEditingName"
                                    >Click To Edit</span
                                >
                            </div>
                        </template>
                    </SheetHeader>

                    <section>
                        <div class="space-y-6">
                            <div
                                :class="[
                                    'grid gap-x-12 gap-y-6',
                                    usesExternalDueDates
                                        ? 'grid-cols-3'
                                        : 'grid-cols-2',
                                ]"
                            >
                                <div
                                    class="flex h-8 items-center justify-between border-b border-gray-200/50 pb-2"
                                >
                                    <span
                                        class="text-[11px] font-medium text-gray-500"
                                        >Assignee</span
                                    >
                                    <Select
                                        :model-value="assigneeValue"
                                        @update:model-value="
                                            (val) =>
                                                handleUpdate('assignee_id', val)
                                        "
                                    >
                                        <SelectTrigger
                                            class="h-auto w-auto rounded-md border-none bg-transparent p-0 px-2 py-1 shadow-none transition-all outline-none hover:bg-gray-200/50"
                                        >
                                            <span
                                                class="text-[10px] font-black tracking-wider text-gray-700 uppercase"
                                                >{{ assigneeLabel }}</span
                                            >
                                        </SelectTrigger>
                                        <SelectContent
                                            align="end"
                                            class="min-w-[160px]"
                                        >
                                            <SelectItem
                                                value="unassigned"
                                                class="text-[10px] font-bold text-gray-400 uppercase"
                                                >Unassigned</SelectItem
                                            >
                                            <SelectItem
                                                v-for="option in assigneeOptions"
                                                :key="option.value"
                                                :value="option.value"
                                                class="text-[10px] font-bold uppercase"
                                            >
                                                {{ option.label }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div
                                    class="flex min-h-8 items-center justify-between border-b border-gray-200/50 pb-2"
                                >
                                    <span
                                        class="text-[11px] font-medium text-gray-500"
                                    >
                                        <template v-if="usesExternalDueDates"
                                            >Internal<br />Due Date</template
                                        >
                                        <template v-else>Due Date</template>
                                    </span>
                                    <input
                                        type="date"
                                        :value="
                                            fieldValue('due_at')
                                                ? (
                                                      fieldValue(
                                                          'due_at',
                                                      ) as string
                                                  ).slice(0, 10)
                                                : ''
                                        "
                                        @change="
                                            (e) =>
                                                handleUpdate(
                                                    'due_at',
                                                    (
                                                        e.target as HTMLInputElement
                                                    ).value,
                                                )
                                        "
                                        class="w-[100px] cursor-pointer border-none bg-transparent p-0 text-right text-[10px] font-black tracking-wider text-gray-700 uppercase focus:ring-0"
                                    />
                                </div>

                                <div
                                    v-if="usesExternalDueDates"
                                    class="flex min-h-8 items-center justify-between border-b border-gray-200/50 pb-2"
                                >
                                    <span
                                        class="text-[11px] font-medium text-gray-500"
                                        >External<br />Due Date</span
                                    >
                                    <input
                                        type="date"
                                        :value="
                                            fieldValue('external_due_at')
                                                ? (
                                                      fieldValue(
                                                          'external_due_at',
                                                      ) as string
                                                  ).slice(0, 10)
                                                : ''
                                        "
                                        @change="
                                            (e) =>
                                                handleUpdate(
                                                    'external_due_at',
                                                    (
                                                        e.target as HTMLInputElement
                                                    ).value,
                                                )
                                        "
                                        class="w-[100px] cursor-pointer border-none bg-transparent p-0 text-right text-[10px] font-black tracking-wider text-gray-700 uppercase focus:ring-0"
                                    />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-x-12 gap-y-6">
                                <div
                                    class="flex h-8 items-center justify-between border-b border-gray-200/50 pb-2"
                                >
                                    <span
                                        class="text-[11px] font-medium text-gray-500"
                                        >Status</span
                                    >
                                    <Select
                                        :model-value="
                                            fieldValue(
                                                'task_status',
                                                'todo',
                                            ) as string
                                        "
                                        @update:model-value="
                                            (val) =>
                                                handleUpdate('task_status', val)
                                        "
                                    >
                                        <SelectTrigger
                                            class="h-auto w-auto rounded-md border-none bg-transparent p-0 px-2 py-1 shadow-none transition-all outline-none hover:bg-gray-200/50"
                                        >
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <span
                                                    class="text-[10px] font-black tracking-wider text-gray-700 uppercase"
                                                    ><SelectValue
                                                /></span>
                                                <div
                                                    :class="[
                                                        kanbanDotClasses[
                                                            currentColumn?.color ??
                                                                'slate'
                                                        ],
                                                        'h-2 w-2 rounded-full',
                                                    ]"
                                                ></div>
                                            </div>
                                        </SelectTrigger>
                                        <SelectContent align="end">
                                            <SelectItem
                                                v-for="column in columns"
                                                :key="column.key"
                                                :value="column.key"
                                                class="text-[10px] font-black uppercase"
                                            >
                                                <div
                                                    class="flex w-24 items-center justify-between"
                                                >
                                                    {{ column.label }}
                                                    <div
                                                        :class="[
                                                            kanbanDotClasses[
                                                                column.color ??
                                                                    'slate'
                                                            ],
                                                            'h-2 w-2 rounded-full',
                                                        ]"
                                                    ></div>
                                                </div>
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div
                                    class="flex h-8 items-center justify-between border-b border-gray-200/50 pb-2"
                                >
                                    <span
                                        class="text-[11px] font-medium text-gray-500"
                                        >Priority</span
                                    >
                                    <Select
                                        :model-value="
                                            fieldValue(
                                                'priority',
                                                'low',
                                            ) as string
                                        "
                                        @update:model-value="
                                            (val) =>
                                                handleUpdate('priority', val)
                                        "
                                    >
                                        <SelectTrigger
                                            class="h-auto w-auto rounded-md border-none bg-transparent p-0 px-2 py-1 shadow-none transition-all outline-none hover:bg-gray-200/50"
                                        >
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <span
                                                    class="text-[10px] font-black tracking-wider text-gray-700 uppercase"
                                                    ><SelectValue
                                                /></span>
                                                <div
                                                    :class="[
                                                        priorityDotClasses[
                                                            fieldValue(
                                                                'priority',
                                                                'low',
                                                            ) as string
                                                        ],
                                                        'h-2 w-2 rounded-full',
                                                    ]"
                                                ></div>
                                            </div>
                                        </SelectTrigger>
                                        <SelectContent align="end">
                                            <SelectItem
                                                v-for="(
                                                    label, key
                                                ) in PRIORITY_LABELS"
                                                :key="key"
                                                :value="key"
                                                class="text-[10px] font-black uppercase"
                                            >
                                                <div
                                                    class="flex w-24 items-center justify-between"
                                                >
                                                    {{ label }}
                                                    <div
                                                        :class="[
                                                            priorityDotClasses[
                                                                key
                                                            ],
                                                            'h-2 w-2 rounded-full',
                                                        ]"
                                                    ></div>
                                                </div>
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </div>

                        <h4
                            class="mt-10 text-[11px] font-black tracking-widest text-gray-400 uppercase"
                        >
                            Content
                        </h4>
                        <div
                            class="document-detail-content mt-4 max-w-none overflow-hidden rounded-md border border-slate-200 transition-all focus-within:border-transparent focus-within:ring-2 focus-within:ring-projector-primary-500 dark:border-white/10 dark:bg-white/5"
                        >
                            <div
                                v-if="editor"
                                class="flex items-center gap-1 border-b border-slate-100 bg-slate-50/50 p-2 dark:border-white/10 dark:bg-white/5"
                            >
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="h-8 w-8 dark:text-slate-300"
                                    @click="
                                        editor
                                            .chain()
                                            .focus()
                                            .toggleBold()
                                            .run()
                                    "
                                    :class="{
                                        'bg-slate-200 dark:bg-white/20':
                                            editor.isActive('bold'),
                                    }"
                                >
                                    <Bold class="h-4 w-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="h-8 w-8 dark:text-slate-300"
                                    @click="
                                        editor
                                            .chain()
                                            .focus()
                                            .toggleItalic()
                                            .run()
                                    "
                                    :class="{
                                        'bg-slate-200 dark:bg-white/20':
                                            editor.isActive('italic'),
                                    }"
                                >
                                    <Italic class="h-4 w-4" />
                                </Button>
                                <div
                                    class="mx-1 h-4 w-px bg-slate-200 dark:bg-white/20"
                                ></div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="h-8 w-8 dark:text-slate-300"
                                    @click="
                                        editor
                                            .chain()
                                            .focus()
                                            .toggleBulletList()
                                            .run()
                                    "
                                    :class="{
                                        'bg-slate-200 dark:bg-white/20':
                                            editor.isActive('bulletList'),
                                    }"
                                >
                                    <List class="h-4 w-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="h-8 w-8 dark:text-slate-300"
                                    @click="
                                        editor
                                            .chain()
                                            .focus()
                                            .toggleOrderedList()
                                            .run()
                                    "
                                    :class="{
                                        'bg-slate-200 dark:bg-white/20':
                                            editor.isActive('orderedList'),
                                    }"
                                >
                                    <ListOrdered class="h-4 w-4" />
                                </Button>
                                <div
                                    class="mx-1 h-4 w-px bg-slate-200 dark:bg-white/20"
                                ></div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="h-8 w-8 dark:text-slate-300"
                                    @click="triggerUpload"
                                >
                                    <Paperclip class="h-4 w-4" />
                                </Button>
                            </div>
                            <editor-content :editor="editor" />
                        </div>
                        <p
                            v-if="mode === 'create' && errors.content"
                            class="pt-1 text-[10px] font-bold text-red-500 uppercase"
                        >
                            {{ errors.content }}
                        </p>
                        <div class="mt-3 flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="
                                    mode === 'create'
                                        ? isCreatingTask
                                        : !isContentDirty || isSavingContent
                                "
                                class="h-8 text-[10px] font-black tracking-widest uppercase"
                                @click="
                                    mode === 'create'
                                        ? emit('update:open', false)
                                        : cancelContentEdit()
                                "
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                :disabled="
                                    mode === 'create'
                                        ? isCreatingTask
                                        : !isContentDirty || isSavingContent
                                "
                                class="h-8 bg-projector-primary-600 text-[10px] font-black tracking-widest uppercase hover:bg-projector-primary-700"
                                @click="
                                    mode === 'create'
                                        ? createTask()
                                        : saveContent()
                                "
                            >
                                {{
                                    (
                                        mode === 'create'
                                            ? isCreatingTask
                                            : isSavingContent
                                    )
                                        ? 'Saving…'
                                        : 'Save'
                                }}
                            </Button>
                        </div>

                        <template v-if="documentProject">
                            <h4
                                class="mt-10 mb-4 text-[11px] font-black tracking-widest text-gray-400 uppercase"
                            >
                                Tags
                            </h4>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    v-for="category in appliedCategories"
                                    :key="category.id"
                                    type="button"
                                    :title="`Remove '${category.name}' tag`"
                                    class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-xs font-bold text-gray-700 hover:border-gray-300 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-gray-200"
                                    @click="removeTag(category)"
                                >
                                    <span
                                        :class="[
                                            kanbanDotClasses[category.color],
                                            'h-2 w-2 shrink-0 rounded-full',
                                        ]"
                                    ></span>
                                    {{ category.name }}
                                </button>
                                <span
                                    v-if="
                                        !appliedCategories.length &&
                                        !availableTagsToAdd.length
                                    "
                                    class="text-xs text-gray-400"
                                    >—</span
                                >

                                <Popover v-if="availableTagsToAdd.length">
                                    <PopoverTrigger as-child>
                                        <button
                                            type="button"
                                            title="Add a tag"
                                            class="flex h-6 w-6 items-center justify-center rounded-full border border-dashed border-gray-300 text-gray-400 hover:border-projector-primary-300 hover:text-projector-primary-600"
                                        >
                                            <Plus class="h-3.5 w-3.5" />
                                        </button>
                                    </PopoverTrigger>
                                    <PopoverContent
                                        class="w-48 p-1"
                                        align="start"
                                    >
                                        <button
                                            v-for="category in availableTagsToAdd"
                                            :key="category.id"
                                            type="button"
                                            class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-xs font-bold text-gray-700 hover:bg-slate-100 dark:text-gray-200 dark:hover:bg-white/10"
                                            @click="addTag(category)"
                                        >
                                            <span
                                                :class="[
                                                    kanbanDotClasses[
                                                        category.color
                                                    ],
                                                    'h-2 w-2 shrink-0 rounded-full',
                                                ]"
                                            ></span>
                                            {{ category.name }}
                                        </button>
                                    </PopoverContent>
                                </Popover>
                            </div>
                        </template>

                        <div
                            v-if="mode !== 'create'"
                            class="mt-10 border-t border-gray-100 pt-8 dark:border-gray-800"
                        >
                            <h4
                                class="mb-4 text-[11px] font-black tracking-widest text-gray-400 uppercase"
                            >
                                Discussion
                            </h4>
                            <CommentSection
                                :comments="document!.comments ?? []"
                                commentable-type="document"
                                :commentable-id="document!.id"
                                :mentionable-users="mentionableUsers"
                                :project-id="String(document!.project_id)"
                                @changed="
                                    emit('comments-changed', document!.id)
                                "
                            />
                        </div>
                    </section>
                </div>
            </template>
        </SheetContent>
    </Sheet>

    <ConfirmDeleteModal
        v-if="mode !== 'create' && document"
        :open="isDeleteModalOpen"
        :title="`Delete ${documentTypeLabel}`"
        :description="`Are you sure you want to delete '${document.name}'? This action cannot be undone.`"
        :loading="isDeleting"
        @confirm="confirmDeletion"
        @close="isDeleteModalOpen = false"
    />
</template>

<style scoped>
/* Same rich-content styling as DocumentContent.vue's own :deep() rules, kept in sync by
   hand since AI-generated HTML is injected via v-html and isn't part of the template
   during initial compilation in either place. */
.document-detail-content :deep(ol) {
    list-style-type: decimal;
    padding-left: 1.5rem;
    margin-top: 1rem;
    margin-bottom: 1rem;
}
.document-detail-content :deep(ol li) {
    margin-bottom: 0.5rem;
}
.document-detail-content :deep(ul) {
    list-style-type: disc;
    padding-left: 1.5rem;
}
.document-detail-content :deep(p) {
    margin-top: 1rem;
    margin-bottom: 1rem;
}
.document-detail-content :deep(h1),
.document-detail-content :deep(h2),
.document-detail-content :deep(h3),
.document-detail-content :deep(h4) {
    font-weight: 800;
    color: rgb(15 23 42);
    margin-top: 1.75rem;
    margin-bottom: 0.75rem;
}
:global(html.dark) .document-detail-content :deep(h1),
:global(html.dark) .document-detail-content :deep(h2),
:global(html.dark) .document-detail-content :deep(h3),
:global(html.dark) .document-detail-content :deep(h4) {
    color: rgb(241 245 249);
}
.document-detail-content :deep(h1) {
    font-size: 1.5rem;
}
.document-detail-content :deep(h2) {
    font-size: 1.25rem;
}
.document-detail-content :deep(h3) {
    font-size: 1.1rem;
}
.document-detail-content :deep(h4) {
    font-size: 1rem;
}
.document-detail-content :deep(strong) {
    font-weight: 700;
}
.document-detail-content :deep(table) {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
    margin-bottom: 1rem;
}
.document-detail-content :deep(th),
.document-detail-content :deep(td) {
    border: 1px solid rgb(226 232 240);
    padding: 0.5rem 0.75rem;
    text-align: left;
    vertical-align: top;
}
.document-detail-content :deep(th) {
    font-weight: 700;
    background-color: rgb(248 250 252);
}
:global(html.dark) .document-detail-content :deep(th),
:global(html.dark) .document-detail-content :deep(td) {
    border-color: rgb(51 65 85);
}
:global(html.dark) .document-detail-content :deep(th) {
    background-color: rgb(30 41 59);
}
</style>

<script setup lang="ts">
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
} from '@/components/ui/select';
import type { AssigneeOption } from '@/lib/assignees';
import {
    PRIORITY_LABELS,
    kanbanDotClasses,
    priorityDotClasses,
} from '@/lib/constants';
import {
    KANBAN_UI,
    dueDateCardBorderColor,
    getAvatarAppearance,
    getPriorityStyles,
    kanbanCardBg,
} from '@/lib/kanban-theme';
import { Calendar, Plus } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    doc: ProjectDocument;
    column: KanbanColumnDef;
    // Resolved once per project by the parent board (KanbanColumn.vue) via a shared Map,
    // instead of every card independently scanning the full projects list and re-merging
    // + re-sorting that project's assignees — real, redundant work when many cards from
    // the same project are visible at once (e.g. clearing the tag filter).
    documentProject: Project | null;
    assigneeOptions: AssigneeOption[];
}>();

// Define emits to handle the keyboard and click actions consistently
const emit = defineEmits<{
    (e: 'open', doc: ProjectDocument): void;
    (e: 'update-attribute', field: string, value: string | number | null): void;
    (e: 'update-tags', categories: CategoryDef[]): void;
}>();

// Helper for initials
const getInitials = (user: any) =>
    (user.first_name?.[0] || '') + (user.last_name?.[0] || '') || user.name[0];

// Pending invitations have no `.name` (just first/last, which may themselves be unset
// on older invitations sent before name capture existed) — falls back to email.
const pendingAssigneeName = (inv: OrganizationInvitation) =>
    [inv.first_name, inv.last_name].filter(Boolean).join(' ') || inv.email;

const pendingAssigneeInitials = (inv: OrganizationInvitation) =>
    (inv.first_name?.[0] || '') + (inv.last_name?.[0] || '') ||
    inv.email[0].toUpperCase();

// Card background is always the neutral gray tint, regardless of column color; only
// the border is tinted red once a task is overdue/due today (and not done).
const dueDateBorder = computed(() =>
    dueDateCardBorderColor(props.doc, props.column),
);

// Tags already on this task never show up again as an "add" option — only the family's
// remaining, not-yet-applied tags do.
const availableTagsToAdd = computed(() => {
    const appliedIds = new Set((props.doc.categories ?? []).map((c) => c.id));
    return (props.documentProject?.categories ?? []).filter(
        (c) => !appliedIds.has(c.id),
    );
});

const addTag = (category: CategoryDef) => {
    emit('update-tags', [...(props.doc.categories ?? []), category]);
};

const removeTag = (category: CategoryDef) => {
    emit(
        'update-tags',
        (props.doc.categories ?? []).filter((c) => c.id !== category.id),
    );
};

const assigneeValue = computed(() => {
    if (props.doc.pending_assignee_invitation_id) {
        return `inv:${props.doc.pending_assignee_invitation_id}`;
    }
    return props.doc.assignee_id?.toString() ?? 'unassigned';
});

const handleUpdate = (field: string, value: any) => {
    let finalValue = value;

    // The backend's resolveAssignee() interprets both plain numeric ids and "inv:{id}"
    // strings itself — no client-side parsing needed (see DocumentSidebar.vue's own
    // handler, which passes the select value straight through the same way).
    if (field === 'assignee_id') {
        finalValue = value === 'unassigned' ? null : value;
    }

    if (field === 'due_at') {
        finalValue = value === '' ? null : value;
    }

    emit('update-attribute', field, finalValue);
};
</script>

<template>
    <div
        :class="[
            KANBAN_UI.card,
            kanbanCardBg.slate,
            'group p-5 hover:border-projector-primary-200',
        ]"
        :style="dueDateBorder ? { borderColor: dueDateBorder } : undefined"
        tabindex="0"
        role="button"
        :aria-label="`Open task: ${doc.name}`"
        @click="emit('open', doc)"
        @keydown.enter.prevent="emit('open', doc)"
        @keydown.space.prevent="emit('open', doc)"
    >
        <div class="-mt-2.5 mb-0.5 flex justify-end">
            <div @click.stop @keydown.stop class="shrink-0">
                <Select
                    :model-value="doc.priority ?? 'low'"
                    @update:model-value="(val) => handleUpdate('priority', val)"
                >
                    <SelectTrigger
                        class="h-auto w-auto gap-0 border-none bg-transparent p-0 shadow-none [&>svg]:hidden"
                    >
                        <div
                            :class="[
                                KANBAN_UI.badge,
                                getPriorityStyles(doc.priority ?? 'low'),
                            ]"
                        >
                            {{ doc.priority ?? 'low' }}
                        </div>
                    </SelectTrigger>
                    <SelectContent align="end">
                        <SelectItem
                            v-for="(label, key) in PRIORITY_LABELS"
                            :key="key"
                            :value="key"
                            class="text-[10px] font-black uppercase"
                        >
                            <div class="flex w-24 items-center justify-between">
                                {{ label }}
                                <div
                                    :class="[
                                        priorityDotClasses[key],
                                        'h-2 w-2 rounded-full',
                                    ]"
                                ></div>
                            </div>
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </div>
        <h4
            :class="[
                KANBAN_UI.cardTitle,
                'mb-5 transition-colors group-hover:text-projector-primary-600',
            ]"
        >
            {{ doc.name }}
        </h4>
        <div class="flex items-center justify-start gap-x-2 gap-y-2">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <div @click.stop @keydown.stop>
                    <Select
                        :model-value="assigneeValue"
                        @update:model-value="
                            (val) => handleUpdate('assignee_id', val)
                        "
                    >
                        <SelectTrigger
                            class="h-auto w-auto gap-0 border-none bg-transparent p-0 shadow-none [&>svg]:hidden"
                            :title="
                                doc.assignee?.name ??
                                (doc.pending_assignee
                                    ? `${pendingAssigneeName(doc.pending_assignee)} (hasn't logged in yet)`
                                    : 'Unassigned')
                            "
                        >
                            <div
                                v-if="doc.assignee"
                                :class="[
                                    KANBAN_UI.avatar,
                                    'h-8 w-8',
                                    getAvatarAppearance(doc.assignee.id),
                                ]"
                            >
                                <span
                                    class="text-[10px] font-black tracking-tighter"
                                >
                                    {{ getInitials(doc.assignee) }}
                                </span>
                            </div>
                            <div
                                v-else-if="doc.pending_assignee"
                                :class="[
                                    KANBAN_UI.avatar,
                                    'h-8 w-8 opacity-50 grayscale',
                                    getAvatarAppearance(
                                        doc.pending_assignee.id,
                                    ),
                                ]"
                            >
                                <span
                                    class="text-[10px] font-black tracking-tighter"
                                >
                                    {{
                                        pendingAssigneeInitials(
                                            doc.pending_assignee,
                                        )
                                    }}
                                </span>
                            </div>
                            <div
                                v-else
                                class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-dashed border-gray-200 bg-white hover:border-projector-primary-300"
                            >
                                <Plus class="h-3 w-3 text-gray-200" />
                            </div>
                        </SelectTrigger>
                        <SelectContent align="start">
                            <SelectItem
                                value="unassigned"
                                class="text-[10px] font-bold text-gray-400 uppercase"
                            >
                                Unassigned
                            </SelectItem>
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
            </div>

            <div
                class="-mx-1.5 -my-0.5 flex items-center gap-1.5 rounded px-1.5 py-0.5 text-gray-500 transition-colors hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/10"
                @click.stop
                @keydown.stop
            >
                <Calendar class="h-4 w-4 shrink-0" />
                <input
                    type="date"
                    :value="doc.due_at ? doc.due_at.slice(0, 10) : ''"
                    @change="
                        (e) =>
                            handleUpdate(
                                'due_at',
                                (e.target as HTMLInputElement).value,
                            )
                    "
                    class="w-[122px] cursor-pointer border-none bg-transparent p-0 text-[13px] font-bold text-gray-700 uppercase focus:ring-0 dark:text-gray-200 [&::-webkit-calendar-picker-indicator]:h-4 [&::-webkit-calendar-picker-indicator]:w-4 [&::-webkit-calendar-picker-indicator]:cursor-pointer"
                />
            </div>
        </div>

        <div
            v-if="(documentProject?.categories?.length ?? 0) > 0"
            class="mt-2 flex flex-wrap items-center gap-1.5"
            @click.stop
            @keydown.stop
        >
            <button
                v-for="category in doc.categories ?? []"
                :key="category.id"
                type="button"
                :title="`Remove '${category.name}' tag`"
                :class="[
                    KANBAN_UI.badge,
                    'inline-flex items-center gap-1 hover:opacity-70',
                ]"
                @click="removeTag(category)"
            >
                <div
                    :class="[
                        kanbanDotClasses[category.color],
                        'h-2 w-2 rounded-full',
                    ]"
                ></div>
                {{ category.name }}
            </button>

            <Popover v-if="availableTagsToAdd.length">
                <PopoverTrigger as-child>
                    <button
                        type="button"
                        title="Add a tag"
                        class="flex h-5 w-5 items-center justify-center rounded-full border border-dashed border-gray-300 text-gray-400 hover:border-projector-primary-300 hover:text-projector-primary-600"
                    >
                        <Plus class="h-3 w-3" />
                    </button>
                </PopoverTrigger>
                <PopoverContent class="w-48 p-1" align="start">
                    <button
                        v-for="category in availableTagsToAdd"
                        :key="category.id"
                        type="button"
                        class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-xs font-bold text-gray-700 hover:bg-slate-100 dark:text-gray-200 dark:hover:bg-white/10"
                        @click="addTag(category)"
                    >
                        <div
                            :class="[
                                kanbanDotClasses[category.color],
                                'h-2 w-2 shrink-0 rounded-full',
                            ]"
                        ></div>
                        {{ category.name }}
                    </button>
                </PopoverContent>
            </Popover>
        </div>
    </div>
</template>

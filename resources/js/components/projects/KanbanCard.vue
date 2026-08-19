<script setup lang="ts">
import {
    KANBAN_UI,
    dueDateCardBorderColor,
    getAvatarAppearance,
    getPriorityStyles,
    kanbanCardBg,
} from '@/lib/kanban-theme';
import { PRIORITY_LABELS, priorityDotClasses } from '@/lib/constants';
import { mergeAssigneeOptions } from '@/lib/assignees';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
} from '@/components/ui/select';
import { Calendar, Plus, Link2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps<{ doc: ProjectDocument; column: KanbanColumnDef }>();

// Define emits to handle the keyboard and click actions consistently
const emit = defineEmits<{
    (e: 'open', doc: ProjectDocument): void;
    (e: 'update-attribute', field: string, value: string | number | null): void;
}>();

// Helper for initials
const getInitials = (user: any) =>
    (user.first_name?.[0] || '') + (user.last_name?.[0] || '') || user.name[0];

// Pending invitations have no `.name` (just first/last, which may themselves be unset
// on older invitations sent before name capture existed) — falls back to email.
const pendingAssigneeName = (inv: OrganizationInvitation) =>
    [inv.first_name, inv.last_name].filter(Boolean).join(' ') || inv.email;

const pendingAssigneeInitials = (inv: OrganizationInvitation) =>
    ((inv.first_name?.[0] || '') + (inv.last_name?.[0] || '')) || inv.email[0].toUpperCase();

// Card background is always the neutral gray tint, regardless of column color; only
// the border is tinted red once a task is overdue/due today (and not done).
const dueDateBorder = computed(() =>
    dueDateCardBorderColor(props.doc, props.column),
);

// Mirrors DocumentSidebar.vue's project resolution — on the single-project page
// `currentProject` already has the right client/organization/users; on the cross-project
// Dashboard board there's no single current project, so resolve this card's own document
// back to its project via the full `projects` list shared on every page.
const page = usePage<AppPageProps>();
const currentProject = computed(() => (page.props as any).currentProject as Project | null);
const allProjects = computed(() => (page.props as any).projects as Project[] | undefined);
const documentProject = computed(() =>
    allProjects.value?.find((p) => p.id === props.doc.project_id) ?? currentProject.value,
);

// Real users and invited people merged into one alphabetically-sorted list, matching
// DocumentSidebar.vue's own assignee picker — client.users (as opposed to
// client.organization.users) is empty for most clients, so that path isn't usable here.
const assigneeOptions = computed(() =>
    mergeAssigneeOptions(
        documentProject.value?.client?.organization?.users,
        documentProject.value?.client?.organization?.invitations,
    ),
);

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
        <h4
            :class="[
                KANBAN_UI.cardTitle,
                'mb-1 transition-colors group-hover:text-projector-primary-600',
            ]"
        >
            {{ doc.name }}
        </h4>
        <div class="mb-5 flex items-center gap-1.5 text-xs">
            {{ doc.type_label ?? doc.type }}
            <!-- Cards native to this board never carry is_linked at all — see
                 Project::getKanbanDocuments() — so this only ever shows on a card that's
                 cross-posted here from elsewhere in the project's subproject family. -->
            <span
                v-if="doc.is_linked"
                :title="`Shown here from ${doc.home_project_name ?? 'another board'} — editing it updates it everywhere it's shown.`"
                class="inline-flex items-center gap-1 rounded-full border border-projector-primary-200 bg-projector-primary-50 px-1.5 py-0.5 text-[9px] font-black tracking-wide text-projector-primary-600 uppercase dark:border-projector-primary-800 dark:bg-projector-primary-950/40 dark:text-projector-primary-400"
            >
                <Link2 class="h-2.5 w-2.5" />
                {{ doc.home_project_name }}
            </span>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <div @click.stop @keydown.stop>
                <Select
                    :model-value="assigneeValue"
                    @update:model-value="(val) => handleUpdate('assignee_id', val)"
                >
                    <SelectTrigger
                        class="h-auto w-auto gap-0 border-none bg-transparent p-0 shadow-none [&>svg]:hidden"
                        :title="doc.assignee?.name ?? (doc.pending_assignee ? `${pendingAssigneeName(doc.pending_assignee)} (hasn't logged in yet)` : 'Unassigned')"
                    >
                        <div
                            v-if="doc.assignee"
                            :class="[
                                KANBAN_UI.avatar,
                                'h-8 w-8',
                                getAvatarAppearance(doc.assignee.id),
                            ]"
                        >
                            <span class="text-[10px] font-black tracking-tighter">
                                {{ getInitials(doc.assignee) }}
                            </span>
                        </div>
                        <div
                            v-else-if="doc.pending_assignee"
                            :class="[
                                KANBAN_UI.avatar,
                                'h-8 w-8 grayscale opacity-50',
                                getAvatarAppearance(doc.pending_assignee.id),
                            ]"
                        >
                            <span class="text-[10px] font-black tracking-tighter">
                                {{ pendingAssigneeInitials(doc.pending_assignee) }}
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
                        <SelectItem value="unassigned" class="text-[10px] font-bold text-gray-400 uppercase">
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

            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <div
                    class="flex items-center gap-1 text-gray-400"
                    @click.stop
                    @keydown.stop
                >
                    <Calendar class="h-3 w-3 shrink-0" />
                    <input
                        type="date"
                        :value="doc.due_at ? doc.due_at.slice(0, 10) : ''"
                        @change="(e) => handleUpdate('due_at', (e.target as HTMLInputElement).value)"
                        :class="[KANBAN_UI.subtleLabel, 'w-[92px] cursor-pointer border-none bg-transparent p-0 focus:ring-0']"
                    />
                </div>

                <div @click.stop @keydown.stop>
                    <Select
                        :model-value="doc.priority ?? 'low'"
                        @update:model-value="(val) => handleUpdate('priority', val)"
                    >
                        <SelectTrigger class="h-auto w-auto gap-0 border-none bg-transparent p-0 shadow-none [&>svg]:hidden">
                            <div :class="[KANBAN_UI.badge, getPriorityStyles(doc.priority ?? 'low')]">
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
                                    <div :class="[priorityDotClasses[key], 'h-2 w-2 rounded-full']"></div>
                                </div>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>
        </div>
    </div>
</template>

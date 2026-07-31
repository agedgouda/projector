<script setup lang="ts">
import {
    KANBAN_UI,
    dueDateCardBackground,
    getAvatarAppearance,
    getPriorityStyles,
    kanbanCardBg,
} from '@/lib/kanban-theme';
import { Calendar, Plus } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ doc: ProjectDocument; column: KanbanColumnDef }>();

// Define emits to handle the keyboard and click actions consistently
const emit = defineEmits(['open']);

// Helper for initials
const getInitials = (user: any) =>
    (user.first_name?.[0] || '') + (user.last_name?.[0] || '') || user.name[0];

// Cards are tinted by due-date urgency (green -> yellow -> red) instead of column color;
// cards with no due date (and not in the "done" column) fall back to the column's tint.
const dueDateBg = computed(() =>
    dueDateCardBackground(props.doc, props.column),
);
</script>

<template>
    <div
        :class="[
            KANBAN_UI.card,
            !dueDateBg ? kanbanCardBg[column.color ?? 'slate'] : '',
            'group p-5 hover:border-projector-primary-200',
        ]"
        :style="dueDateBg ? { backgroundColor: dueDateBg } : undefined"
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
        <div class="mb-5 text-xs">
            {{ doc.type_label ?? doc.type }}
        </div>
        <div class="flex items-center justify-between">
            <div class="flex -space-x-2">
                <div
                    v-if="doc.assignee"
                    :class="[
                        KANBAN_UI.avatar,
                        'h-8 w-8',
                        getAvatarAppearance(doc.assignee.id),
                    ]"
                    :title="doc.assignee.name"
                >
                    <span class="text-[10px] font-black tracking-tighter">
                        {{ getInitials(doc.assignee) }}
                    </span>
                </div>
                <div
                    v-else
                    class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-dashed border-gray-200 bg-white"
                >
                    <Plus class="h-3 w-3 text-gray-200" />
                </div>
            </div>

            <div class="flex items-center gap-3">
                <div
                    v-if="doc.due_at"
                    class="flex items-center gap-1 text-gray-400"
                >
                    <Calendar class="h-3 w-3" />
                    <span :class="KANBAN_UI.subtleLabel">
                        {{
                            new Date(doc.due_at).toLocaleDateString(undefined, {
                                month: 'short',
                                day: 'numeric',
                            })
                        }}
                    </span>
                </div>

                <div
                    v-if="doc.priority"
                    :class="[KANBAN_UI.badge, getPriorityStyles(doc.priority)]"
                >
                    {{ doc.priority }}
                </div>
            </div>
        </div>
    </div>
</template>

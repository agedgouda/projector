<script setup lang="ts">
import { Calendar, Plus } from 'lucide-vue-next';
import { KANBAN_UI, getPriorityStyles, getAvatarAppearance } from '@/lib/kanban-theme';

defineProps<{ doc: ProjectDocument }>();

// Define emits to handle the keyboard and click actions consistently
const emit = defineEmits(['open']);

// Helper for initials
const getInitials = (user: any) =>
    (user.first_name?.[0] || '') + (user.last_name?.[0] || '') || user.name[0];
</script>

<template>
    <div
        :class="[KANBAN_UI.card, 'p-5 group hover:border-indigo-200']"
        tabindex="0"
        role="button"
        :aria-label="`Open task: ${doc.name}`"
        @click="emit('open', doc)"
        @keydown.enter.prevent="emit('open', doc)"
        @keydown.space.prevent="emit('open', doc)"
    >
        <h4 :class="[KANBAN_UI.cardTitle, 'mb-5 group-hover:text-indigo-600 transition-colors']">
            {{ doc.name }}
        </h4>

        <div class="flex items-center justify-between">
            <div class="flex -space-x-2">
                <div v-if="doc.assignee"
                     :class="[
                         KANBAN_UI.avatar,
                         'w-8 h-8',
                         getAvatarAppearance(doc.assignee.id)
                     ]"
                     :title="doc.assignee.name"
                >
                    <span class="text-[10px] font-black tracking-tighter">
                        {{ getInitials(doc.assignee) }}
                    </span>
                </div>
                <div v-else class="w-8 h-8 rounded-full border-2 border-dashed border-gray-200 bg-white flex items-center justify-center">
                    <Plus class="w-3 h-3 text-gray-200" />
                </div>
            </div>

            <div class="flex items-center gap-3">
                <div v-if="doc.due_at" class="flex items-center gap-1 text-gray-400">
                    <Calendar class="w-3 h-3" />
                    <span :class="KANBAN_UI.subtleLabel">
                        {{ new Date(doc.due_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) }}
                    </span>
                </div>

                <div v-if="doc.priority" :class="[KANBAN_UI.badge, getPriorityStyles(doc.priority)]">
                    {{ doc.priority }}
                </div>
            </div>
        </div>
    </div>
</template>

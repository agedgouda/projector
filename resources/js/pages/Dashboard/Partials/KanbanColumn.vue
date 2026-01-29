<script setup lang="ts">
import draggable from 'vuedraggable';
import { Plus } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import KanbanCard from './KanbanCard.vue';
import { KANBAN_UI } from '@/lib/kanban-theme';

defineProps<{
    status: TaskStatus;
    tasks: ProjectDocument[];
    rowLabel: string;
    canCreateTask: (status: TaskStatus) => boolean;
}>();

const emit = defineEmits(['drag', 'open', 'create']);
</script>

<template>
    <div :class="KANBAN_UI.columnWrapper">
        <draggable
            :model-value="tasks"
            :group="{ name: `tasks-${rowLabel}` }"
            item-key="id"
            class="flex-1 space-y-4 min-h-[100px]"
            :ghost-class="KANBAN_UI.ghostCard"
            @change="emit('drag', $event)"
        >
            <template #item="{ element: doc }">
                <KanbanCard :doc="doc" @click="emit('open', doc)" />
            </template>
        </draggable>

        <Button
            v-if="canCreateTask(status)"
            variant="ghost"
            @click="emit('create')"
            :class="[
                'w-full h-14 border border-dashed border-gray-200/80 rounded-2xl transition-all bg-transparent shadow-none mt-auto hover:text-indigo-600 hover:bg-white',
                KANBAN_UI.subtleLabel
            ]"
        >
            <Plus class="w-4 h-4 mr-2" /> New {{ rowLabel }}
        </Button>
    </div>
</template>

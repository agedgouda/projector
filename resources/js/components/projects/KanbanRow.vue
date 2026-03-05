<script setup lang="ts">
import KanbanColumn from './KanbanColumn.vue';
import { KANBAN_UI } from '@/lib/kanban-theme'; // Import theme

defineProps<{
    row: any;
    columnStatuses: TaskStatus[];
    canCreateTask: (status: TaskStatus) => boolean;
    gridStyle: Record<string, string>;
    getTasks: (rowKey: string, status: TaskStatus) => ProjectDocument[];
    onDrag: (evt: any, status: TaskStatus) => void;
    onOpen: (doc: ProjectDocument) => void;
    onCreate: (rowKey: string) => void;
}>();
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center gap-4 px-2">
            <h3 :class="[KANBAN_UI.label, 'text-indigo-900 bg-indigo-50/80 px-2 py-1 rounded-md border border-indigo-100/50']">
                {{ row.label }}
            </h3>
            <div class="h-px flex-1 bg-gradient-to-r from-indigo-100/50 to-transparent"></div>
        </div>

        <div class="grid gap-8" :style="KANBAN_UI.gridContainer(columnStatuses.length)">
            <template v-for="status in columnStatuses" :key="status">
                <KanbanColumn
                    :status="status"
                    :tasks="getTasks(row.key, status)"
                    :row-label="row.label"
                    :can-create-task="canCreateTask"
                    @drag="(evt) => onDrag(evt, status)"
                    @open="onOpen"
                    @create="onCreate(row.key)"
                />
            </template>
        </div>
    </div>
</template>

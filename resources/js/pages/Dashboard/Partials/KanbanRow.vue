<script setup lang="ts">
import KanbanColumn from './KanbanColumn.vue';

defineProps<{
    row: any;
    columnStatuses: TaskStatus[];

    // 1. ADD THESE THREE LINES:
    isColumnVisible: (status: TaskStatus) => boolean;
    canCreateTask: (status: TaskStatus) => boolean;
    gridStyle: Record<string, string>;

    // Existing props...
    getTasks: (rowKey: string, status: TaskStatus) => ProjectDocument[];
    onDrag: (evt: any, status: TaskStatus) => void;
    onOpen: (doc: ProjectDocument) => void;
    onCreate: (rowKey: string) => void;
}>();
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center gap-4 px-2">
            <h3 class="text-[9px] font-black uppercase tracking-widest text-indigo-900 bg-indigo-50/80 px-2 py-1 rounded-md border border-indigo-100/50">
                {{ row.label }}
            </h3>
            <div class="h-px flex-1 bg-gradient-to-r from-indigo-100/50 to-transparent"></div>
        </div>

        <div class="grid grid-cols-4 gap-8">
            <template v-for="status in columnStatuses" :key="status">
            <KanbanColumn
                v-if="isColumnVisible(status)"
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

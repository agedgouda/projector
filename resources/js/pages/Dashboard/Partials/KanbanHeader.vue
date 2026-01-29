<script setup lang="ts">
import { STATUS_LABELS, statusDotClasses } from '@/lib/constants';
import { KANBAN_UI } from '@/lib/kanban-theme';

defineProps<{
    columnStatuses: TaskStatus[];
    getCount: (status: TaskStatus) => number;
}>();
</script>

<template>
    <div
        class="sticky top-0 bg-white/90 backdrop-blur-md z-20 border-b border-gray-100/50 px-4 w-full block"
        :style="KANBAN_UI.gridContainer(columnStatuses.length)"
    >
        <div
            v-for="status in columnStatuses"
            :key="status"
            :class="KANBAN_UI.columnHeader"
        >
            <div :class="['h-2 w-2 rounded-full shadow-sm', statusDotClasses[status]]"></div>

            <div class="flex items-center gap-2">
                <span :class="KANBAN_UI.headerTitle">
                    {{ STATUS_LABELS[status] }}
                </span>

                <span class="flex items-center justify-center bg-gray-100 text-gray-500 text-[9px] font-black px-1.5 py-0.5 rounded-full min-w-[20px] border border-gray-200/50">
                    {{ getCount(status) }}
                </span>
            </div>
        </div>
    </div>
</template>

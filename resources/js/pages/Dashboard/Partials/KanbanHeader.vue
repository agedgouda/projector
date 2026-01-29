<script setup lang="ts">
import { computed } from 'vue';
import { STATUS_LABELS, statusDotClasses } from '@/lib/constants';
import { KANBAN_UI } from '@/lib/kanban-theme';

const props = defineProps<{
    columnStatuses: TaskStatus[];
    getCount: (status: TaskStatus) => number;
}>();

// Calculate the grid columns based on how many statuses are actually being passed
const gridStyle = computed(() => ({
    gridTemplateColumns: `repeat(${props.columnStatuses.length}, minmax(0, 1fr))`
}));
</script>

<template>
    <div
        class="grid gap-8 px-4 sticky top-0 bg-white/90 backdrop-blur-md z-20 py-0 border-b border-gray-100/50"
        :style="gridStyle"
    >
        <div v-for="status in columnStatuses" :key="status" :class="KANBAN_UI.columnHeader">
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

<script setup lang="ts">
import { computed } from 'vue';

// Dependency-free pie chart (CSS conic-gradient) for the /dashboard2 redesign demo — avoids
// pulling in a charting library for a design iteration that may not survive review.
const props = defineProps<{
    title?: string;
    segments: { label: string; count: number; color: string }[];
    size?: number;
}>();

const size = computed(() => props.size ?? 64);
const total = computed(() => props.segments.reduce((sum, s) => sum + s.count, 0));

const gradient = computed(() => {
    if (total.value === 0) return '#e2e8f0';

    let acc = 0;
    const stops = props.segments
        .filter((s) => s.count > 0)
        .map((s) => {
            const start = (acc / total.value) * 360;
            acc += s.count;
            const end = (acc / total.value) * 360;
            return `${s.color} ${start}deg ${end}deg`;
        });

    return `conic-gradient(${stops.join(', ')})`;
});
</script>

<template>
    <div class="flex items-center gap-3">
        <div
            class="shrink-0 rounded-full"
            :style="{ width: `${size}px`, height: `${size}px`, background: gradient }"
        ></div>

        <div class="min-w-0">
            <p v-if="title" class="mb-0.5 truncate text-[11px] font-bold text-gray-700 dark:text-gray-300">{{ title }}</p>
            <ul class="space-y-0.5">
                <li v-for="segment in segments" :key="segment.label" class="flex items-center gap-1.5 text-[10px] text-gray-500 dark:text-gray-400">
                    <span class="h-1.5 w-1.5 shrink-0 rounded-full" :style="{ backgroundColor: segment.color }"></span>
                    {{ segment.label }}: {{ segment.count }}
                </li>
            </ul>
        </div>
    </div>
</template>

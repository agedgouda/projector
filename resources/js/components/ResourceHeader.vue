<script setup lang="ts">
import { ChevronRight } from 'lucide-vue-next';

defineProps<{
    title: string;
    count?: number;
    collapsed?: boolean;
}>();

defineEmits(['toggle']);
</script>

<template>
    <div
        @click="$emit('toggle')"
        class="group/header flex items-center gap-2.5 mt-8 mb-2 px-2 h-9 rounded-md cursor-pointer select-none w-full transition-colors hover:bg-slate-200/80 dark:hover:bg-slate-700/60"
    >
        <ChevronRight
            class="w-3.5 h-3.5 text-slate-400 transition-transform duration-200"
            :class="{ 'rotate-90': !collapsed }"
        />

        <slot name="title" v-if="$slots.title" />

        <h3 v-else class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-400 group-hover/header:text-slate-600 dark:group-hover/header:text-slate-300 transition-colors">
            {{ title }}
        </h3>

        <span v-if="count !== undefined" class="text-[10px] font-bold text-slate-400">
            {{ count }}
        </span>
    </div>
</template>

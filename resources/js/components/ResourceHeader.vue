<script setup lang="ts">
import { ChevronDown } from 'lucide-vue-next';

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
        class="flex items-center gap-4 mt-10 mb-6 cursor-pointer group/header select-none w-full"
    >
        <div class="flex items-center justify-center w-6 h-6 rounded-lg bg-gray-100 dark:bg-gray-800 group-hover/header:bg-indigo-500 group-hover/header:text-white transition-all">
            <ChevronDown
                class="w-3.5 h-3.5 transition-transform duration-300"
                :class="{ '-rotate-90': collapsed }"
            />
        </div>
        <div class="flex items-center gap-3">
            <h3 class="text-sm font-black uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400 group-hover/header:text-indigo-600 transition-colors">
                {{ title }}
            </h3>
            <span v-if="count !== undefined" class="text-[10px] font-mono font-bold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-500/10 px-2 py-0.5 rounded-full border border-indigo-100 dark:border-indigo-500/20">
                {{ count }}
            </span>
        </div>
        <div class="h-px bg-gradient-to-r from-gray-200 to-transparent dark:from-gray-700 flex-1"></div>
    </div>
</template>

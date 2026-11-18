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
        class="group/header mt-8 mb-2 flex h-9 w-full cursor-pointer items-center gap-2.5 rounded-md border-b border-slate-200 px-2 transition-colors select-none dark:border-slate-800"
    >
        <ChevronRight
            class="h-3.5 w-3.5 text-slate-400 transition-transform duration-200"
            :class="{ 'rotate-90': !collapsed }"
        />

        <slot name="title" v-if="$slots.title" />

        <h3
            v-else
            class="text-[11px] font-black tracking-[0.2em] text-projector-primary-600 uppercase dark:text-projector-primary-400"
        >
            {{ title }}
        </h3>

        <span
            v-if="count !== undefined"
            class="text-[10px] font-bold text-slate-400"
        >
            {{ count }}
        </span>
    </div>
</template>

<script setup lang="ts">
import { ChevronDown } from 'lucide-vue-next';
import type { Component } from 'vue';
import IconTile from '@/components/IconTile.vue';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';

withDefaults(defineProps<{
    iconSrc?: string | null;
    iconAlt?: string;
    iconFallback: Component;
    eyebrow: string;
    title: string;
}>(), {
    iconSrc: null,
    iconAlt: '',
});
</script>

<template>
    <button type="button" :class="['group flex items-center gap-3 h-12 px-3 rounded-md transition-colors', FLAT_ROW_HOVER]">
        <IconTile :src="iconSrc" :alt="iconAlt" :icon="iconFallback" size="md" tone="muted" />

        <div class="text-left min-w-0">
            <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 leading-none mb-1">{{ eyebrow }}</p>
            <p class="text-[15px] font-bold text-slate-900 dark:text-slate-100 leading-tight truncate">{{ title }}</p>
        </div>

        <ChevronDown class="w-4 h-4 text-slate-300 group-hover:text-slate-400 transition-colors shrink-0" />
    </button>
</template>

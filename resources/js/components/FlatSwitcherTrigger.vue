<script setup lang="ts">
import IconTile from '@/components/IconTile.vue';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';
import { ChevronDown } from '@lucide/vue';
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        iconSrc?: string | null;
        iconAlt?: string;
        iconFallback: Component;
        eyebrow: string;
        title: string;
    }>(),
    {
        iconSrc: null,
        iconAlt: '',
    },
);
</script>

<template>
    <button
        type="button"
        :class="[
            'group flex h-12 items-center gap-3 rounded-md px-3 transition-colors',
            FLAT_ROW_HOVER,
        ]"
    >
        <IconTile
            :src="iconSrc"
            :alt="iconAlt"
            :icon="iconFallback"
            size="md"
            tone="muted"
        />

        <div class="min-w-0 text-left">
            <p
                class="mb-1 text-[9px] leading-none font-black tracking-widest text-slate-400 uppercase"
            >
                {{ eyebrow }}
            </p>
            <p
                class="truncate text-[15px] leading-tight font-bold text-slate-900 dark:text-slate-100"
            >
                {{ title }}
            </p>
        </div>

        <ChevronDown
            class="h-4 w-4 shrink-0 text-slate-300 transition-colors group-hover:text-slate-400"
        />
    </button>
</template>

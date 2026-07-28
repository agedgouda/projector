<script setup lang="ts">
import { ArrowLeft } from 'lucide-vue-next';
import { router } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const props = defineProps<{
    title?: string;
    backHref?: string;
}>();

const goBack = () => {
    if (props.backHref) {
        router.visit(props.backHref);
    }
};
</script>

<template>
    <div
        class="h-dvh overflow-hidden bg-slate-50 dark:bg-slate-950 flex flex-col"
        style="padding-top: env(safe-area-inset-top); padding-bottom: env(safe-area-inset-bottom);"
    >
        <header
            v-if="title"
            class="sticky top-0 z-10 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-white/10 px-1 h-14 grid grid-cols-[3rem_1fr_3rem] items-center shrink-0"
        >
            <button
                v-if="backHref"
                type="button"
                @click="goBack"
                class="h-11 w-11 flex items-center justify-center text-slate-500 dark:text-slate-400 active:opacity-60"
            >
                <ArrowLeft class="w-5 h-5" />
            </button>
            <!-- No back target means this is a top-level screen (currently just the
                 dashboard) — show the brand mark in that slot instead of leaving it empty. -->
            <div v-else class="h-11 w-11 flex items-center justify-center">
                <AppLogoIcon class="size-7 fill-current text-slate-900 dark:text-white" />
            </div>

            <h1 class="text-lg font-black text-slate-900 dark:text-white truncate text-center">{{ title }}</h1>

            <div class="h-11 w-11 flex items-center justify-center">
                <slot name="actions" />
            </div>
        </header>

        <main class="flex-1 overflow-y-auto">
            <slot />
        </main>
    </div>
</template>

<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';

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
        class="flex h-dvh flex-col overflow-hidden bg-slate-50 dark:bg-slate-950"
        style="
            padding-top: env(safe-area-inset-top);
            padding-bottom: env(safe-area-inset-bottom);
        "
    >
        <header
            v-if="title"
            class="sticky top-0 z-10 grid h-14 shrink-0 grid-cols-[3rem_1fr_3rem] items-center border-b border-slate-200 bg-white px-1 dark:border-white/10 dark:bg-slate-900"
        >
            <button
                v-if="backHref"
                type="button"
                @click="goBack"
                class="flex h-11 w-11 items-center justify-center text-slate-500 active:opacity-60 dark:text-slate-400"
            >
                <ArrowLeft class="h-5 w-5" />
            </button>
            <!-- No back target means this is a top-level screen (currently just the
                 dashboard) — show the brand mark in that slot instead of leaving it empty. -->
            <div v-else class="flex h-11 w-11 items-center justify-center">
                <AppLogoIcon
                    class="size-7 fill-current text-slate-900 dark:text-white"
                />
            </div>

            <h1
                class="truncate text-center text-lg font-black text-slate-900 dark:text-white"
            >
                {{ title }}
            </h1>

            <div class="flex h-11 w-11 items-center justify-center">
                <slot name="actions" />
            </div>
        </header>

        <main class="flex-1 overflow-y-auto">
            <slot />
        </main>
    </div>
</template>

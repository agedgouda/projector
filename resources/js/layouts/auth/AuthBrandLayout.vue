<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="grid min-h-svh lg:grid-cols-[1fr_3fr]">
        <div class="relative flex flex-col bg-[#f8f6f3] px-6 py-6 dark:bg-zinc-900 lg:px-10">
            <Link :href="home()" class="flex items-center gap-2.5 self-start">
                <AppLogoIcon class="size-8" />
                <span class="text-base font-bold tracking-wide text-foreground uppercase">Projector</span>
            </Link>

            <div v-if="$slots.aside" class="mx-auto hidden w-full max-w-md flex-1 flex-col justify-center gap-6 py-12 lg:flex">
                <slot name="aside" />
            </div>
        </div>

        <div class="flex items-center justify-center bg-[#efedea] px-4 py-10 dark:bg-zinc-950">
            <div class="w-full max-w-sm rounded-xl bg-white p-7 shadow-lg dark:bg-zinc-900">
                <div v-if="title || description" class="mb-6 flex flex-col gap-1 text-center">
                    <h2 v-if="title" class="text-xl font-semibold text-foreground">{{ title }}</h2>
                    <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
                </div>

                <slot />
            </div>
        </div>
    </div>
</template>

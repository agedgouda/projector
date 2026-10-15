<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import { home } from '@/routes';
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <!-- On wide screens the middle row holds both the card and the aside, so their tops line up
         while the two equal outer rows keep the card vertically centred. -->
    <div
        class="grid min-h-svh grid-rows-[auto_1fr] bg-[#efedea] dark:bg-zinc-950 lg:grid-cols-[16rem_1fr] lg:grid-rows-[minmax(4rem,1fr)_auto_minmax(2.5rem,1fr)]"
    >
        <div class="bg-[#f8f6f3] dark:bg-zinc-900 lg:col-start-1 lg:row-span-3 lg:row-start-1">
            <div class="p-2 pl-4">
                <Link :href="home()" class="flex h-12 items-center gap-2 p-2 text-sm text-foreground">
                    <AppLogo />
                </Link>
            </div>
        </div>

        <div v-if="$slots.aside" class="hidden flex-col gap-6 self-start px-6 lg:col-start-1 lg:row-start-2 lg:flex">
            <slot name="aside" />
        </div>

        <div class="flex items-center justify-center px-4 py-10 lg:col-start-2 lg:row-start-2 lg:py-0">
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

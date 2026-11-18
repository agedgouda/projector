<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
    /** For pages that bring their own side padding and need more room than an article (the FAQ). */
    wide?: boolean;
}>();

const page = usePage<{ auth: { user: unknown } }>();
const isAuthenticated = computed(() => !!page.props.auth?.user);
</script>

<template>
    <AppLayout v-if="isAuthenticated" :breadcrumbs="props.breadcrumbs">
        <slot />
    </AppLayout>

    <div v-else class="min-h-screen bg-[#FDFDFC] dark:bg-slate-950">
        <header
            class="border-b border-slate-100 bg-white dark:border-slate-800 dark:bg-slate-900"
        >
            <div
                :class="[
                    'mx-auto flex items-center justify-between px-6 py-4',
                    props.wide ? 'max-w-screen-2xl' : 'max-w-5xl',
                ]"
            >
                <Link
                    href="/"
                    class="flex items-center gap-2 text-slate-900 dark:text-white"
                >
                    <AppLogoIcon class="h-7 w-7 text-projector-primary-600" />
                    <span
                        class="text-sm font-black tracking-tighter uppercase"
                        >{{ $page.props.name }}</span
                    >
                </Link>
                <Link
                    href="/login"
                    class="text-[11px] font-black tracking-widest text-slate-500 uppercase transition-colors hover:text-projector-primary-600 dark:text-slate-400 dark:hover:text-projector-primary-400"
                >
                    Log in
                </Link>
            </div>
        </header>
        <main
            :class="[
                'mx-auto',
                props.wide ? 'max-w-screen-2xl' : 'max-w-5xl px-6 py-8',
            ]"
        >
            <slot />
        </main>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

const page = usePage<{ auth: { user: unknown } }>();
const isAuthenticated = computed(() => !!page.props.auth?.user);
</script>

<template>
    <AppLayout v-if="isAuthenticated" :breadcrumbs="props.breadcrumbs">
        <slot />
    </AppLayout>

    <div v-else class="min-h-screen bg-[#FDFDFC] dark:bg-slate-950">
        <header class="border-b border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900">
            <div class="mx-auto max-w-5xl px-6 py-4 flex items-center justify-between">
                <Link href="/" class="flex items-center gap-2 text-slate-900 dark:text-white">
                    <AppLogoIcon class="h-7 w-7 text-indigo-600" />
                    <span class="font-black tracking-tighter text-sm uppercase">{{ $page.props.name }}</span>
                </Link>
                <Link
                    href="/login"
                    class="text-[11px] font-black uppercase tracking-widest text-slate-500 hover:text-indigo-600 dark:text-slate-400 dark:hover:text-indigo-400 transition-colors"
                >
                    Log in
                </Link>
            </div>
        </header>
        <main class="mx-auto max-w-5xl px-6 py-8">
            <slot />
        </main>
    </div>
</template>

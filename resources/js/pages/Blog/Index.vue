<script setup lang="ts">
import PublicLayout from '@/layouts/PublicLayout.vue';
import { formatDateMdy } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Calendar } from 'lucide-vue-next';

interface BlogPost {
    id: string;
    slug: string;
    title: string;
    date: string | null;
}

defineProps<{
    posts: BlogPost[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Blog', href: '/blog' }];

const formatDate = (date: string | null) => {
    if (!date) return null;
    return formatDateMdy(date);
};
</script>

<template>
    <Head title="Blog" />

    <PublicLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-8 p-6">
            <div>
                <h1
                    class="text-2xl font-black tracking-tighter text-gray-900 uppercase dark:text-white"
                >
                    Blog
                </h1>
                <p class="mt-1 text-sm text-gray-500">
                    Latest news and updates.
                </p>
            </div>

            <div
                v-if="posts.length === 0"
                class="rounded-3xl border-2 border-dashed border-gray-100 py-24 text-center dark:border-gray-800"
            >
                <h3
                    class="text-xs font-black tracking-widest text-gray-400 uppercase"
                >
                    No posts yet
                </h3>
            </div>

            <div v-else class="grid gap-4">
                <Link
                    v-for="post in posts"
                    :key="post.id"
                    :href="`/blog/${post.slug}`"
                    class="group block rounded-2xl border border-slate-200 bg-white p-6 transition-all hover:border-projector-primary-300 hover:shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:hover:border-projector-primary-700"
                >
                    <h2
                        class="mb-2 text-base font-bold text-slate-900 transition-colors group-hover:text-projector-primary-600 dark:text-white dark:group-hover:text-projector-primary-400"
                    >
                        {{ post.title }}
                    </h2>
                    <div
                        v-if="post.date"
                        class="mt-2 flex items-center gap-1.5 text-[11px] text-slate-400 dark:text-slate-500"
                    >
                        <Calendar class="h-3 w-3" />
                        {{ formatDate(post.date) }}
                    </div>
                </Link>
            </div>
        </div>
    </PublicLayout>
</template>

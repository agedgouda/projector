<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { type BreadcrumbItem } from '@/types';
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
    return new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
};
</script>

<template>
    <Head title="Blog" />

    <PublicLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full space-y-8">

            <div>
                <h1 class="text-2xl font-black tracking-tighter text-gray-900 dark:text-white uppercase">Blog</h1>
                <p class="text-sm text-gray-500 mt-1">Latest news and updates.</p>
            </div>

            <div v-if="posts.length === 0" class="py-24 text-center border-2 border-dashed border-gray-100 dark:border-gray-800 rounded-3xl">
                <h3 class="font-black text-gray-400 uppercase tracking-widest text-xs">No posts yet</h3>
            </div>

            <div v-else class="grid gap-4">
                <Link
                    v-for="post in posts"
                    :key="post.id"
                    :href="`/blog/${post.slug}`"
                    class="block bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-6 hover:border-projector-primary-300 dark:hover:border-projector-primary-700 hover:shadow-sm transition-all group"
                >
                    <h2 class="text-base font-bold text-slate-900 dark:text-white group-hover:text-projector-primary-600 dark:group-hover:text-projector-primary-400 transition-colors mb-2">
                        {{ post.title }}
                    </h2>
                    <div v-if="post.date" class="flex items-center gap-1.5 text-[11px] text-slate-400 dark:text-slate-500 mt-2">
                        <Calendar class="w-3 h-3" />
                        {{ formatDate(post.date) }}
                    </div>
                </Link>
            </div>

        </div>
    </PublicLayout>
</template>

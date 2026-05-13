<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Calendar, ArrowLeft } from 'lucide-vue-next';

interface BlogPost {
    id: string;
    slug: string;
    title: string;
    content: string | null;
    date: string | null;
}

const props = defineProps<{
    post: BlogPost;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Blog', href: '/blog' },
    { title: props.post.title ?? 'Post', href: `/blog/${props.post.slug}` },
];

const formatDate = (date: string | null) => {
    if (!date) return null;
    return new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
};
</script>

<template>
    <Head :title="post.title ?? 'Blog Post'" />

    <PublicLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full max-w-3xl space-y-8">

            <div>
                <Link
                    href="/blog"
                    class="inline-flex items-center gap-1.5 text-[11px] font-black uppercase tracking-widest text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors mb-6"
                >
                    <ArrowLeft class="w-3.5 h-3.5" /> Back to Blog
                </Link>

                <h1 class="text-2xl font-black tracking-tighter text-gray-900 dark:text-white mt-2">
                    {{ post.title }}
                </h1>

                <div v-if="post.date" class="flex items-center gap-1.5 text-[11px] text-slate-400 dark:text-slate-500 mt-3">
                    <Calendar class="w-3 h-3" />
                    {{ formatDate(post.date) }}
                </div>
            </div>

            <div
                v-if="post.content"
                class="prose-content"
                v-html="post.content"
            />

            <div v-else class="py-12 text-center border-2 border-dashed border-gray-100 dark:border-gray-800 rounded-3xl">
                <p class="text-sm text-slate-400">No content available.</p>
            </div>

        </div>
    </PublicLayout>
</template>

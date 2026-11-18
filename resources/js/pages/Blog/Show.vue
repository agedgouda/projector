<script setup lang="ts">
import PublicLayout from '@/layouts/PublicLayout.vue';
import { formatDateMdy } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Calendar } from 'lucide-vue-next';

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
    return formatDateMdy(date);
};
</script>

<template>
    <Head :title="post.title ?? 'Blog Post'" />

    <PublicLayout :breadcrumbs="breadcrumbs">
        <div class="w-full max-w-3xl space-y-8 p-6">
            <div>
                <Link
                    href="/blog"
                    class="mb-6 inline-flex items-center gap-1.5 text-[11px] font-black tracking-widest text-slate-400 uppercase transition-colors hover:text-projector-primary-600 dark:hover:text-projector-primary-400"
                >
                    <ArrowLeft class="h-3.5 w-3.5" /> Back to Blog
                </Link>

                <h1
                    class="mt-2 text-2xl font-black tracking-tighter text-gray-900 dark:text-white"
                >
                    {{ post.title }}
                </h1>

                <div
                    v-if="post.date"
                    class="mt-3 flex items-center gap-1.5 text-[11px] text-slate-400 dark:text-slate-500"
                >
                    <Calendar class="h-3 w-3" />
                    {{ formatDate(post.date) }}
                </div>
            </div>

            <div
                v-if="post.content"
                class="prose-content"
                v-html="post.content"
            />

            <div
                v-else
                class="rounded-3xl border-2 border-dashed border-gray-100 py-12 text-center dark:border-gray-800"
            >
                <p class="text-sm text-slate-400">No content available.</p>
            </div>
        </div>
    </PublicLayout>
</template>

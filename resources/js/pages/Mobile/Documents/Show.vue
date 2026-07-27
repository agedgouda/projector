<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import MobileLayout from '@/layouts/MobileLayout.vue';
import DOMPurify from 'dompurify';
import { RefreshCw, ListChecks } from 'lucide-vue-next';
import mobileProjectRoutes from '@/routes/mobile/projects';

defineProps<{
    project: { id: string; name: string };
    document: { id: string; name: string; content: string | null; status: 'processing' | 'processed' };
    children: Array<{ id: string; name: string; type: string; content: string | null }>;
}>();

const sanitize = (html: string | null) => DOMPurify.sanitize(html ?? '');
</script>

<template>
    <Head :title="document.name" />

    <MobileLayout :title="document.name" :back-href="mobileProjectRoutes.show(project.id).url">
        <div class="p-4 space-y-6">
            <div
                v-if="document.status === 'processing'"
                class="flex items-center gap-2 bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400 rounded-2xl p-4 text-sm font-bold"
            >
                <RefreshCw class="w-4 h-4 animate-spin shrink-0" />
                Still processing…
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-white/10 p-5">
                <div class="note-content text-[15px] text-slate-900 dark:text-slate-300 leading-relaxed" v-html="sanitize(document.content) || 'No content yet.'"></div>
            </div>

            <div v-if="children.length > 0" class="space-y-3">
                <div class="flex items-center gap-2 px-1">
                    <ListChecks class="w-4 h-4 text-slate-400" />
                    <h2 class="text-[11px] font-black uppercase tracking-widest text-slate-400">Action Items</h2>
                </div>
                <div
                    v-for="child in children"
                    :key="child.id"
                    class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-white/10 p-4"
                >
                    <p class="font-bold text-slate-900 dark:text-white">{{ child.name }}</p>
                    <div v-if="child.content" class="note-content text-[13px] text-slate-500 mt-1" v-html="sanitize(child.content)"></div>
                </div>
            </div>
        </div>
    </MobileLayout>
</template>

<style scoped>
.note-content :deep(p) {
    margin-top: 0.75rem;
    margin-bottom: 0.75rem;
}
.note-content :deep(h1),
.note-content :deep(h2),
.note-content :deep(h3) {
    font-weight: 800;
    margin-top: 1.25rem;
    margin-bottom: 0.5rem;
}
.note-content :deep(ol) {
    list-style-type: decimal;
    padding-left: 1.5rem;
}
.note-content :deep(ul) {
    list-style-type: disc;
    padding-left: 1.5rem;
}
</style>

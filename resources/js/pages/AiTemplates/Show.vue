<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import {
    Wand2,
    Settings2,
    ArrowLeft,
    Info,
    Sparkles,
    Terminal,
    Copy
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { toast } from 'vue-sonner';

// Wayfinder Routes
import aiTemplateRoutes from '@/routes/ai-templates';

const props = defineProps<{
    aiTemplate: {
        id: number;
        name: string;
        system_prompt: string;
        user_prompt: string;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'AI Templates', href: aiTemplateRoutes.index().url },
    { title: props.aiTemplate.name, href: '#' },
];

const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    toast.success('Prompt copied to clipboard');
};

const handleEdit = () => {
    router.visit(aiTemplateRoutes.edit({ ai_template: props.aiTemplate.id }).url);
};

const goBack = () => {
    router.visit(aiTemplateRoutes.index().url);
};
</script>

<template>
    <Head :title="aiTemplate.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="max-w-5xl p-8 mx-auto w-full">

            <div class="flex items-center justify-between mb-10">
                <button
                    @click="goBack"
                    class="flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 hover:text-indigo-600 transition-colors group"
                >
                    <ArrowLeft class="w-4 h-4 group-hover:-translate-x-1 transition-transform" />
                    Back to Library
                </button>

                <Button
                    @click="handleEdit"
                    class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-gray-900 dark:text-white hover:bg-gray-50 font-black px-6 rounded-xl shadow-sm transition-all active:scale-95 text-[10px] uppercase tracking-widest"
                >
                    <Settings2 class="w-4 h-4 mr-2 text-indigo-500" />
                    Edit Configuration
                </Button>
            </div>

            <div class="mb-12 flex items-center gap-6">
                <div class="h-20 w-20 rounded-[2rem] bg-indigo-600 flex items-center justify-center text-white shadow-xl shadow-indigo-500/20">
                    <Wand2 class="w-10 h-10" />
                </div>
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <Terminal class="w-3 h-3 text-indigo-500" />
                        <span class="text-[10px] font-black uppercase tracking-[0.3em] text-indigo-500/60">Intelligence Protocol</span>
                    </div>
                    <h1 class="text-4xl font-black tracking-tighter text-gray-900 dark:text-white uppercase italic">
                        {{ aiTemplate.name }}
                    </h1>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-8">

                <section class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2 text-[10px] font-black uppercase tracking-widest text-gray-400">
                            <Info class="w-4 h-4 text-amber-500" />
                            System Persona (Instructions)
                        </div>
                        <button @click="copyToClipboard(aiTemplate.system_prompt)" class="text-[10px] font-bold text-indigo-500 hover:underline flex items-center gap-1">
                            <Copy class="w-3 h-3" /> Copy
                        </button>
                    </div>
                    <div class="bg-white dark:bg-gray-950 border border-gray-100 dark:border-gray-800 rounded-[2rem] p-8 shadow-sm">
                        <p class="text-lg leading-relaxed text-gray-700 dark:text-gray-300 font-medium">
                            {{ aiTemplate.system_prompt }}
                        </p>
                    </div>
                </section>

                <section class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2 text-[10px] font-black uppercase tracking-widest text-gray-400">
                            <Sparkles class="w-4 h-4 text-indigo-500" />
                            Transformation Logic (Prompt)
                        </div>
                        <button @click="copyToClipboard(aiTemplate.user_prompt)" class="text-[10px] font-bold text-indigo-500 hover:underline flex items-center gap-1">
                            <Copy class="w-3 h-3" /> Copy
                        </button>
                    </div>
                    <div class="bg-gray-900 dark:bg-black rounded-[2rem] p-8 shadow-inner border border-gray-800 relative overflow-hidden">
                        <div class="absolute -top-24 -right-24 w-64 h-64 bg-indigo-500/10 blur-[100px] pointer-events-none"></div>

                        <pre class="text-indigo-400 font-mono text-sm leading-7 whitespace-pre-wrap relative z-10">{{ aiTemplate.user_prompt }}</pre>
                    </div>
                </section>

            </div>
        </div>
    </AppLayout>
</template>

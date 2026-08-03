<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import DOMPurify from 'dompurify';
import { ArrowLeft, Copy, Settings2, Wand2 } from 'lucide-vue-next';
import { toast } from 'vue-sonner';

// Wayfinder Routes
import aiTemplateRoutes from '@/routes/transformation-library';

const props = defineProps<{
    aiTemplate: {
        id: number;
        name: string;
        description: string | null;
        generation_brief: string | null;
        system_prompt: string;
        user_prompt: string;
        single_output: boolean;
    };
    canEdit: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Transformations', href: aiTemplateRoutes.index().url },
    { title: props.aiTemplate.name, href: '' },
];

const renderPrompt = (text: string) => DOMPurify.sanitize(text);

const copyToClipboard = (text: string) => {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    } else {
        const el = document.createElement('textarea');
        el.value = text;
        el.style.position = 'fixed';
        el.style.opacity = '0';
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    }
    toast.success('Prompt copied to clipboard');
};

const handleEdit = () => {
    router.visit(
        aiTemplateRoutes.edit({ aiTemplate: props.aiTemplate.id }).url,
    );
};

const goBack = () => {
    router.visit(aiTemplateRoutes.index().url);
};
</script>

<template>
    <Head :title="aiTemplate.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full p-6">
            <div class="mb-10 flex items-center justify-between">
                <button
                    @click="goBack"
                    class="group flex items-center gap-2 text-[10px] font-black tracking-[0.2em] text-gray-400 uppercase transition-colors hover:text-projector-primary-600"
                >
                    <ArrowLeft
                        class="h-4 w-4 transition-transform group-hover:-translate-x-1"
                    />
                    Back to Library
                </button>

                <Button
                    v-if="canEdit"
                    @click="handleEdit"
                    class="border border-gray-200 bg-white px-6 text-gray-900 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                >
                    <Settings2
                        class="mr-2 h-4 w-4 text-projector-primary-500"
                    />
                    Edit Configuration
                </Button>
            </div>

            <div class="mb-12 flex items-center gap-6">
                <div
                    class="flex h-20 w-20 items-center justify-center rounded-[2rem] bg-projector-primary-600 text-white shadow-xl shadow-projector-primary-500/30"
                >
                    <Wand2 class="h-10 w-10" />
                </div>
                <div>
                    <div class="mb-1 flex items-center gap-2">
                        <span
                            class="text-[10px] font-black tracking-[0.3em] text-projector-primary-500/60 uppercase"
                            >Intelligence Protocol</span
                        >
                    </div>
                    <h1
                        class="text-4xl font-black tracking-tighter text-gray-900 uppercase italic dark:text-white"
                    >
                        {{ aiTemplate.name }}
                    </h1>
                </div>
            </div>

            <div v-if="aiTemplate.description" class="mb-12">
                <p class="max-w-2xl text-sm text-gray-500 dark:text-gray-400">
                    {{ aiTemplate.description }}
                </p>
            </div>

            <Tabs v-if="aiTemplate.generation_brief" default-value="current">
                <TabsList
                    class="mb-8 h-12 w-full justify-start gap-6 border-b border-gray-100 bg-transparent p-0 dark:border-gray-800"
                >
                    <TabsTrigger
                        value="current"
                        class="relative h-12 rounded-none border-b-2 border-transparent bg-transparent px-0 pt-2 pb-3 text-[10px] font-black tracking-widest text-gray-400 uppercase data-[state=active]:border-projector-primary-600 data-[state=active]:text-projector-primary-600 data-[state=active]:shadow-none"
                    >
                        Current Configuration
                    </TabsTrigger>
                    <TabsTrigger
                        value="generation"
                        class="relative h-12 rounded-none border-b-2 border-transparent bg-transparent px-0 pt-2 pb-3 text-[10px] font-black tracking-widest text-gray-400 uppercase data-[state=active]:border-projector-primary-600 data-[state=active]:text-projector-primary-600 data-[state=active]:shadow-none"
                    >
                        Generation Prompt
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="current" class="mt-0 outline-none">
                    <div class="grid grid-cols-1 gap-8">
                        <section class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div
                                    class="flex items-center gap-2 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                                >
                                    System Persona (Instructions)
                                </div>
                                <button
                                    @click="
                                        copyToClipboard(
                                            aiTemplate.system_prompt,
                                        )
                                    "
                                    class="flex items-center gap-1 text-[10px] font-bold text-projector-primary-500 hover:underline"
                                >
                                    <Copy class="h-3 w-3" /> Copy
                                </button>
                            </div>
                            <div
                                class="rounded-[2rem] border border-gray-100 bg-white p-8 shadow-sm dark:border-gray-800 dark:bg-gray-950"
                            >
                                <div
                                    class="prose-content"
                                    v-html="
                                        renderPrompt(aiTemplate.system_prompt)
                                    "
                                />
                            </div>
                        </section>

                        <section class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div
                                    class="flex items-center gap-2 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                                >
                                    Transformation Logic (Prompt)
                                </div>
                                <button
                                    @click="
                                        copyToClipboard(aiTemplate.user_prompt)
                                    "
                                    class="flex items-center gap-1 text-[10px] font-bold text-projector-primary-500 hover:underline"
                                >
                                    <Copy class="h-3 w-3" /> Copy
                                </button>
                            </div>
                            <div
                                class="relative overflow-hidden rounded-[2rem] border border-gray-800 bg-gray-900 p-8 shadow-inner dark:bg-black"
                            >
                                <div
                                    class="pointer-events-none absolute -top-24 -right-24 h-64 w-64 bg-projector-primary-500/10 blur-[100px]"
                                ></div>

                                <pre
                                    class="relative z-10 font-mono text-sm leading-7 whitespace-pre-wrap text-projector-primary-400"
                                    >{{ aiTemplate.user_prompt }}</pre
                                >
                            </div>
                        </section>
                    </div>
                </TabsContent>

                <TabsContent value="generation" class="mt-0 outline-none">
                    <section class="space-y-4">
                        <div class="flex items-center justify-end">
                            <button
                                @click="
                                    copyToClipboard(aiTemplate.generation_brief)
                                "
                                class="flex items-center gap-1 text-[10px] font-bold text-projector-primary-500 hover:underline"
                            >
                                <Copy class="h-3 w-3" /> Copy
                            </button>
                        </div>
                        <div
                            class="rounded-[2rem] border border-gray-100 bg-white p-8 shadow-sm dark:border-gray-800 dark:bg-gray-950"
                        >
                            <p
                                class="text-sm leading-relaxed whitespace-pre-wrap text-gray-700 dark:text-gray-300"
                            >
                                {{ aiTemplate.generation_brief }}
                            </p>
                        </div>
                    </section>
                </TabsContent>
            </Tabs>

            <div v-else class="grid grid-cols-1 gap-8">
                <section class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div
                            class="flex items-center gap-2 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                        >
                            System Persona (Instructions)
                        </div>
                        <button
                            @click="copyToClipboard(aiTemplate.system_prompt)"
                            class="flex items-center gap-1 text-[10px] font-bold text-projector-primary-500 hover:underline"
                        >
                            <Copy class="h-3 w-3" /> Copy
                        </button>
                    </div>
                    <div
                        class="rounded-[2rem] border border-gray-100 bg-white p-8 shadow-sm dark:border-gray-800 dark:bg-gray-950"
                    >
                        <div
                            class="prose-content"
                            v-html="renderPrompt(aiTemplate.system_prompt)"
                        />
                    </div>
                </section>

                <section class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div
                            class="flex items-center gap-2 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                        >
                            Transformation Logic (Prompt)
                        </div>
                        <button
                            @click="copyToClipboard(aiTemplate.user_prompt)"
                            class="flex items-center gap-1 text-[10px] font-bold text-projector-primary-500 hover:underline"
                        >
                            <Copy class="h-3 w-3" /> Copy
                        </button>
                    </div>
                    <div
                        class="relative overflow-hidden rounded-[2rem] border border-gray-800 bg-gray-900 p-8 shadow-inner dark:bg-black"
                    >
                        <div
                            class="pointer-events-none absolute -top-24 -right-24 h-64 w-64 bg-projector-primary-500/10 blur-[100px]"
                        ></div>

                        <pre
                            class="relative z-10 font-mono text-sm leading-7 whitespace-pre-wrap text-projector-primary-400"
                            >{{ aiTemplate.user_prompt }}</pre
                        >
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>

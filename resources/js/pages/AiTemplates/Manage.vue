<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import AiTemplateForm from './Partials/AiTemplateForm.vue';
import { type BreadcrumbItem } from '@/types';
import aiTemplateRoutes from '@/routes/ai-templates';
import { toast } from 'vue-sonner';

const props = defineProps<{
    aiTemplate: AiTemplate | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'AI Templates', href: aiTemplateRoutes.index().url },
    { title: props.aiTemplate ? 'Edit Template' : 'New Template', href: '#' },
];

const handleShow = (id: number | null) => {
    // If no ID exists, return to the index library
    if (!id) {
        router.visit(aiTemplateRoutes.index().url);
        return;
    }

    // Otherwise, navigate to the specific protocol details
    router.visit(aiTemplateRoutes.show({ ai_template: id }).url);
};


const form = useForm({
    name: props.aiTemplate?.name ?? '',
    system_prompt: props.aiTemplate?.system_prompt ?? '',
    user_prompt: props.aiTemplate?.user_prompt ?? '',
});

const submit = () => {
    const isEdit = !!props.aiTemplate;

    const route = isEdit
        ? aiTemplateRoutes.update({ ai_template: props.aiTemplate.id })
        : aiTemplateRoutes.store();

    form[isEdit ? 'put' : 'post'](route.url, {
        preserveScroll: true,
        onSuccess: () => {
            // If we just created it, the redirect above will hydrate props.aiTemplate
            // and the UI will automatically switch to "Update" mode.
            toast.success(isEdit ? 'Configuration updated' : 'Intelligence protocol activated');
        },
        onError: () => {
            toast.error('Validation failed. Please check your inputs.');
        }
    });
};
</script>

<template>
    <Head :title="aiTemplate ? 'Edit Template' : 'New Template'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full">
            <button @click="handleShow(aiTemplate?.id ?? null)" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 text-sm font-medium mb-4">
                &larr; Back
            </button>
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white">{{ aiTemplate ? 'Update Template' : 'Configure AI Logic' }}</h1>
                    <p class="text-sm text-gray-500">Define the instructions and context for this transition protocol.</p>
                </div>
            </div>
            <AiTemplateForm
                v-model:name="form.name"
                v-model:system-prompt="form.system_prompt"
                v-model:user-prompt="form.user_prompt"
                :processing="form.processing"
                :errors="form.errors"
                :is-editing="!!aiTemplate"
                @cancel="router.visit(aiTemplateRoutes.index.url())"
                @submit="submit"
            />
        </div>
    </AppLayout>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import aiTemplateRoutes from '@/routes/transformation-library';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AiTemplateForm from './Partials/AiTemplateForm.vue';

const props = defineProps<{
    aiTemplate: AiTemplate | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Transformations', href: aiTemplateRoutes.index().url },
    {
        title: props.aiTemplate ? 'Edit Template' : 'New Transformation',
        href: '#',
    },
];

const handleShow = () => {
    if (props.aiTemplate) {
        router.visit(
            aiTemplateRoutes.show({ aiTemplate: props.aiTemplate.id }).url,
        );
    } else {
        router.visit(aiTemplateRoutes.index().url);
    }
};

const form = useForm({
    name: props.aiTemplate?.name ?? '',
    description: props.aiTemplate?.description ?? '',
    generation_brief: props.aiTemplate?.generation_brief ?? '',
    system_prompt: props.aiTemplate?.system_prompt ?? '',
    user_prompt: props.aiTemplate?.user_prompt ?? '',
    single_output: props.aiTemplate?.single_output ?? true,
    output_key: props.aiTemplate?.output_key ?? '',
});

const submit = () => {
    const isEdit = !!props.aiTemplate;

    const route = isEdit
        ? aiTemplateRoutes.update({ aiTemplate: props.aiTemplate.id })
        : aiTemplateRoutes.store();

    form[isEdit ? 'put' : 'post'](route.url, {
        preserveScroll: true,
        onSuccess: () => {
            // Both create and update redirect to the template's detail page.
            toast.success(
                isEdit
                    ? 'Configuration updated'
                    : 'Intelligence protocol activated',
            );
        },
        onError: () => {
            toast.error('Validation failed. Please check your inputs.');
        },
    });
};
</script>

<template>
    <Head :title="aiTemplate ? 'Edit Template' : 'New Transformation'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full p-6">
            <button
                @click="handleShow()"
                class="mb-4 text-sm font-medium text-projector-primary-600 hover:text-projector-primary-800 dark:text-projector-primary-400 dark:hover:text-projector-primary-300"
            >
                &larr; Back
            </button>
            <div
                class="mb-8 flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center"
            >
                <div>
                    <h1
                        class="text-2xl font-black tracking-tight text-gray-900 dark:text-white"
                    >
                        {{
                            aiTemplate
                                ? 'Update Template'
                                : 'Configure Transformation'
                        }}
                    </h1>
                    <p class="text-sm text-gray-500">
                        Define the instructions and context for this transition
                        protocol.
                    </p>
                </div>
            </div>
            <AiTemplateForm
                v-model:name="form.name"
                v-model:description="form.description"
                v-model:generation-brief="form.generation_brief"
                v-model:system-prompt="form.system_prompt"
                v-model:user-prompt="form.user_prompt"
                v-model:single-output="form.single_output"
                v-model:output-key="form.output_key"
                :processing="form.processing"
                :errors="form.errors"
                :is-editing="!!aiTemplate"
                @cancel="handleShow"
                @submit="submit"
            />
        </div>
    </AppLayout>
</template>

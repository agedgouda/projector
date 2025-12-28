<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import projectRoutes from '@/routes/projects/index';

const props = defineProps<{
    project?: any;
    clients?: any[];
    projectTypes: any[];
    documents?: any[];
    initialClientId?: string | number;
}>();

const emit = defineEmits(['success']);

const form = useForm({
    name: props.project?.name ?? '',
    client_id: props.project?.client_id ?? props.initialClientId ?? '',
    project_type_id: props.project?.project_type_id ?? '',
    document_id: props.project?.document_id ?? '',
    description: props.project?.description ?? '',
});

const submit = () => {
    // Determine the route and method dynamically
    const url = props.project
        ? projectRoutes.update.url(props.project.id)
        : projectRoutes.store.url();

    const method = props.project ? 'put' : 'post';

    form[method](url, {
        preserveScroll: true,
        onSuccess: () => {
            if (!props.project) {
                form.reset(); // Clear form on create
            }
            emit('success'); // Notify parent
        },
    });
};
</script>

<template>
    <form @submit.prevent="submit" class="space-y-4">
        <input
            v-model="form.name"
            type="text"
            placeholder="Project Name"
            class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-white text-sm focus:ring-indigo-500"
            required
        />

        <select
            v-if="!initialClientId && !project && clients"
            v-model="form.client_id"
            class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-white text-sm"
            required
        >
            <option value="" disabled>Select Client</option>
            <option v-for="client in clients" :key="client.id" :value="client.id">
                {{ client.company_name }}
            </option>
        </select>

        <select
            v-model="form.project_type_id"
            class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-white text-sm"
            required
        >
            <option value="" disabled>Select Type</option>
            <option v-for="type in projectTypes" :key="type.id" :value="type.id">
                {{ type.name }}
            </option>
        </select>

        <textarea
            v-model="form.description"
            placeholder="Description..."
            class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-white text-sm"
            rows="3"
        ></textarea>

        <button
            type="submit"
            :disabled="form.processing"
            class="w-full bg-indigo-600 text-white py-2 rounded-lg font-bold text-sm hover:bg-indigo-700 transition"
        >
            {{ form.processing ? 'Saving...' : (project ? 'Update Project' : 'Save Project') }}
        </button>
    </form>
</template>

<script setup lang="ts">
const props = defineProps<{
    modelValue: {
        name: string;
        client_id: string | number;
        project_type_id: string | number;
        description: string;
    };
    clients?: any[]; // Pass this to show the Client dropdown
    projectTypes: any[];
    processing: boolean;
}>();

const emit = defineEmits(['update:modelValue', 'submit']);

const updateField = (field: string, value: any) => {
    emit('update:modelValue', { ...props.modelValue, [field]: value });
};
</script>

<template>
    <form @submit.prevent="$emit('submit')" class="space-y-4">
        <input
            :value="modelValue.name"
            @input="updateField('name', ($event.target as HTMLInputElement).value)"
            placeholder="Project Name"
            class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-white text-sm focus:ring-indigo-500"
            required
        />

        <select
            v-if="clients"
            :value="modelValue.client_id"
            @change="updateField('client_id', ($event.target as HTMLSelectElement).value)"
            class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-white text-sm focus:ring-indigo-500"
            required
        >
            <option value="" disabled selected>Select Client</option>
            <option v-for="client in clients" :key="client.id" :value="client.id">
                {{ client.company_name }}
            </option>
        </select>

        <select
            :value="modelValue.project_type_id"
            @change="updateField('project_type_id', ($event.target as HTMLSelectElement).value)"
            class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-white text-sm focus:ring-indigo-500"
            required
        >
            <option value="" disabled selected>Select Type</option>
            <option v-for="type in projectTypes" :key="type.id" :value="type.id">
                {{ type.name }}
            </option>
        </select>

        <textarea
            :value="modelValue.description"
            @input="updateField('description', ($event.target as HTMLTextAreaElement).value)"
            placeholder="Description..."
            class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-white text-sm focus:ring-indigo-500"
            rows="2"
        ></textarea>

        <button
            type="submit"
            :disabled="processing"
            class="w-full bg-indigo-600 text-white py-2 rounded-lg font-bold text-sm hover:bg-indigo-700 transition disabled:opacity-50"
        >
            {{ processing ? 'Saving...' : 'Save Project' }}
        </button>
    </form>
</template>

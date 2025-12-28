<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import ProjectFolio from '@/components/ProjectFolio.vue';
import ProjectForm from '@/components/ProjectForm.vue';
import projectRoutes from '@/routes/projects/index';

const props = defineProps<{
    client: { id: string, company_name: string };
    projects: any[];
    projectTypes: any[];
}>();

const form = useForm({
    name: '',
    client_id: props.client.id,
    description: '',
    project_type_id: '',
});

const submit = () => {
    form.post(projectRoutes.store.url(), {
        onSuccess: () => {
            form.reset('name', 'description', 'project_type_id');
        },
        only: ['projects'],
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
        <div class="bg-indigo-50 dark:bg-indigo-900/10 p-6 rounded-xl border border-indigo-100 dark:border-indigo-900/30 h-fit">
            <h3 class="font-bold text-indigo-900 dark:text-indigo-300 mb-4 text-sm uppercase tracking-wider">
                Add Project to {{ client.company_name }}
            </h3>
            <ProjectForm
                v-model="form"
                :project-types="projectTypes"
                :processing="form.processing"
                @submit="submit"
            />
        </div>

        <div class="lg:col-span-2 space-y-3">
            <h3 class="font-bold text-gray-500 mb-4 text-sm uppercase tracking-wider">Active Projects</h3>

            <div v-if="projects.length === 0" class="text-center py-10 border-2 border-dashed rounded-xl border-gray-200 dark:border-gray-800 text-gray-400 text-sm">
                No active records found for {{ client.company_name }}.
            </div>

            <ProjectFolio
                v-for="project in projects"
                :key="project.id"
                :project="project"
            />
        </div>
    </div>
</template>

<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import projectTypeRoutes from '@/routes/project-types/index';
import {
    Code, Megaphone, Calendar, Layout, Database,
    Globe, Settings, PenTool, Rocket, Microscope,
    Briefcase, Music, Camera, Zap, Heart
} from 'lucide-vue-next';

const iconLibrary = [
    { name: 'Code', component: Code },
    { name: 'Megaphone', component: Megaphone },
    { name: 'Calendar', component: Calendar },
    { name: 'Layout', component: Layout },
    { name: 'Database', component: Database },
    { name: 'Globe', component: Globe },
    { name: 'Settings', component: Settings },
    { name: 'PenTool', component: PenTool },
    { name: 'Rocket', component: Rocket },
    { name: 'Microscope', component: Microscope },
    { name: 'Briefcase', component: Briefcase },
    { name: 'Music', component: Music },
    { name: 'Camera', component: Camera },
    { name: 'Zap', component: Zap },
    { name: 'Heart', component: Heart }
];

const getIcon = (name: string) => iconLibrary.find(i => i.name === name)?.component || null;

interface ProjectType {
    id: string;
    name: string;
    icon: string | null;
}

const breadcrumbs: BreadcrumbItem[] =
    [
        {
            title: 'Project Types',
            href: projectTypeRoutes.index.url()
        },
    ];

const { projectTypes } = defineProps<{
    projectTypes: ProjectType[];
    errors: any;
}>();

const isEditing = ref(false);
const editingId = ref<string | null>(null);

const form = useForm({
    name: '',
    icon: '',
});

const edit = (type: ProjectType) => {
    isEditing.value = true;
    editingId.value = type.id;
    form.name = type.name;
    form.icon = type.icon ?? '';
};

const cancelEdit = () => {
    isEditing.value = false;
    editingId.value = null;
    form.reset();
};

const submit = () => {
    const url = isEditing.value && editingId.value
        ? projectTypeRoutes.update.url(editingId.value)
        : projectTypeRoutes.store.url();

    const method = isEditing.value ? 'put' : 'post';

    form[method](url, {
        onSuccess: () => cancelEdit(),
        preserveScroll: true,
    });
};

const destroy = (id: string) => {
    if (confirm('Are you sure you want to delete this project type?')) {
        router.delete(projectTypeRoutes.destroy.url(id));
    }
};
</script>

<template>
    <AppLayout title="Project Types"  :breadcrumbs="breadcrumbs">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                    <div class="col-span-1">
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 border border-gray-200">
                            <h3 class="font-bold text-gray-900 mb-4">
                                {{ isEditing ? 'Edit Project Type' : 'Add Project Type' }}
                            </h3>

                            <form @submit.prevent="submit" class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Type Name</label>
                                    <input v-model="form.name" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" />
                                    <div v-if="form.errors.name" class="text-red-600 text-xs mt-1">{{ form.errors.name }}</div>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Select Icon</label>
                                    <div class="grid grid-cols-5 gap-2 p-2 border border-gray-200 rounded-lg max-h-48 overflow-y-auto bg-gray-50">
                                        <button
                                            v-for="icon in iconLibrary"
                                            :key="icon.name"
                                            type="button"
                                            @click="form.icon = icon.name"
                                            :class="[
                                                'p-3 rounded-md flex items-center justify-center transition-all',
                                                form.icon === icon.name
                                                    ? 'bg-indigo-600 text-white shadow-md'
                                                    : 'bg-white text-gray-400 hover:text-indigo-600 hover:border-indigo-300 border border-gray-100'
                                            ]"
                                        >
                                            <component :is="icon.component" class="w-5 h-5" />
                                        </button>
                                    </div>
                                    <p v-if="form.icon" class="text-[10px] mt-2 text-gray-500 font-bold uppercase tracking-wider">
                                        Selected: {{ form.icon }}
                                    </p>
                                </div>

                                <div class="flex items-center gap-3 pt-2">
                                    <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none" :disabled="form.processing">
                                        {{ isEditing ? 'Update' : 'Save' }}
                                    </button>
                                    <button v-if="isEditing" type="button" @click="cancelEdit" class="text-sm text-gray-600 hover:underline">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="col-span-2">
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Icon</th>
                                        <th class="relative px-6 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="type in projectTypes" :key="type.id">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ type.name }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div v-if="type.icon" class="mr-3 p-1.5 bg-gray-100 rounded-md">
                                                    <component :is="getIcon(type.icon)" class="w-4 h-4 text-gray-600" />
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <button @click="edit(type)" class="text-indigo-600 hover:text-indigo-900 mr-4">Edit</button>
                                            <button @click="destroy(type.id)" class="text-red-600 hover:text-red-900">Delete</button>
                                        </td>
                                    </tr>
                                    <tr v-if="projectTypes.length === 0">
                                        <td colspan="3" class="px-6 py-4 text-center text-sm text-gray-500">No project types found.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </AppLayout>
</template>

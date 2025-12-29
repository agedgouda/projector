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

interface DocumentRequirement {
    label: string;
    key: string;
    required: boolean;
}

interface ProjectType {
    id: string;
    name: string;
    icon: string | null;
    document_schema: DocumentRequirement[] | null;
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
    document_schema: [] as DocumentRequirement[],
});

const edit = (type: ProjectType) => {
    isEditing.value = true;
    editingId.value = type.id;
    form.name = type.name;
    form.icon = type.icon ?? '';
    form.document_schema = type.document_schema ? [...type.document_schema] : [];
};

const cancelEdit = () => {
    isEditing.value = false;
    editingId.value = null;
    form.reset('name', 'icon', 'document_schema');
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

const addRequirement = () => {
    form.document_schema.push({ label: '', key: '', required: true });
};

const removeRequirement = (index: number) => {
    form.document_schema.splice(index, 1);
};

// Auto-generate a slug-key when the label is typed
const updateKey = (index: number) => {
    form.document_schema[index].key = form.document_schema[index].label
        .toLowerCase()
        .replace(/[^a-z0-自0-9 ]/g, '')
        .replace(/\s+/g, '_');
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

                                <div class="mt-6 pt-6 border-t border-gray-100">
                                    <div class="flex items-center justify-between mb-4">
                                        <label class="block text-sm font-bold text-gray-700">Document Requirements</label>
                                        <button type="button" @click="addRequirement" class="text-xs bg-indigo-50 text-indigo-600 px-2 py-1 rounded hover:bg-indigo-100 font-medium">
                                            + Add Document
                                        </button>
                                    </div>

                                    <div class="space-y-3">
                                        <div v-for="(doc, index) in form.document_schema" :key="index" class="p-3 bg-gray-50 rounded-lg border border-gray-200 relative group">
                                            <button type="button" @click="removeRequirement(index)" class="absolute -top-2 -right-2 bg-white border border-gray-200 text-gray-400 hover:text-red-500 rounded-full p-1 shadow-sm">
                                                <span class="sr-only">Remove</span>
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>

                                            <div class="grid grid-cols-1 gap-2">
                                                <input
                                                    v-model="doc.label"
                                                    @input="updateKey(index)"
                                                    type="text"
                                                    placeholder="Label (e.g. Tech Spec)"
                                                    class="block w-full text-xs border-gray-300 rounded-md focus:ring-indigo-500"
                                                />
                                                <div class="flex items-center justify-between">
                                                    <code class="text-[10px] text-gray-400">key: {{ doc.key || '...' }}</code>
                                                    <label class="flex items-center gap-2 cursor-pointer">
                                                        <input type="checkbox" v-model="doc.required" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                                        <span class="text-[10px] font-medium text-gray-600 uppercase">Required</span>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <div v-if="form.document_schema.length === 0" class="text-center py-4 border-2 border-dashed border-gray-100 rounded-lg text-gray-400 text-xs">
                                            No documents required for this type.
                                        </div>
                                    </div>
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
                            <div class="px-6 py-3 bg-white border-b border-gray-100 flex justify-between items-center">
                                <h3 class="text-sm font-semibold text-gray-700">Available Types</h3>
                                <div class="flex gap-4 items-center">
                                    <div class="flex items-center gap-1.5">
                                        <div class="w-2 h-2 rounded-full bg-red-500"></div>
                                        <span class="text-[10px] text-gray-500 uppercase font-bold tracking-tight">Required</span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <div class="w-2 h-2 rounded-full bg-gray-300"></div>
                                        <span class="text-[10px] text-gray-500 uppercase font-bold tracking-tight">Optional</span>
                                    </div>
                                </div>
                            </div>

                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Icon</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Document Types</th>
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
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <div class="flex flex-wrap gap-1">
                                                <span
                                                    v-for="doc in type.document_schema"
                                                    :key="doc.key"
                                                    :class="[
                                                        'inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border',
                                                        doc.required
                                                            ? 'bg-red-50 text-red-700 border-red-200'
                                                            : 'bg-gray-50 text-gray-500 border-gray-200'
                                                    ]"
                                                >
                                                    {{ doc.label }}
                                                </span>
                                                <span v-if="!type.document_schema || type.document_schema.length === 0" class="text-gray-400 italic text-xs">
                                                    No requirements
                                                </span>
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

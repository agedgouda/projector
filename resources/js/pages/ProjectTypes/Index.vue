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
import { Input } from '@/components/ui/input';

// ... (iconLibrary and getIcon remain the same)
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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Project Types', href: projectTypeRoutes.index.url() },
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
    // 1. Ensure the 'intake' requirement is ALWAYS in the schema before sending
    const hasIntake = form.document_schema.some(doc => doc.key === 'intake');

    if (!hasIntake) {
        form.document_schema.unshift({
            label: 'Notes',
            key: 'intake',
            required: false
        });
    }

    const url = isEditing.value && editingId.value
        ? projectTypeRoutes.update.url(editingId.value)
        : projectTypeRoutes.store.url();

    const method = isEditing.value ? 'put' : 'post';
    form[method](url, { onSuccess: () => cancelEdit(), preserveScroll: true });
};



const destroy = (id: string) => {
    if (confirm('Are you sure you want to delete this project type?')) {
        router.delete(projectTypeRoutes.destroy.url(id));
    }
};

const addRequirement = () => {
    // use unshift to put the new form at the top of the list
    form.document_schema.unshift({ label: '', key: '', required: false });
};

const suggestKey = (index: number) => {
    const doc = form.document_schema[index];

    // Don't auto-generate if it's our protected 'intake' key
    // or if the user hasn't typed a label yet
    if (doc.key === 'intake' || !doc.label) return;

    doc.key = doc.label
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9 ]/g, '')
        .replace(/\s+/g, '_');
};

const removeRequirement = (index: number) => {
    form.document_schema.splice(index, 1);
};

</script>

<template>
    <AppLayout title="Project Types" :breadcrumbs="breadcrumbs">
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
                                    <Input v-model="form.name" type="text" class="mt-1 block w-full" />
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
                                                form.icon === icon.name ? 'bg-indigo-600 text-white' : 'bg-white text-gray-400 border border-gray-100'
                                            ]"
                                        >
                                            <component :is="icon.component" class="w-5 h-5" />
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-6 pt-6 border-t border-gray-100">
                                    <div class="flex items-center justify-between mb-4">
                                        <label class="block text-sm font-bold text-gray-700">Document Types</label>
                                        <button type="button" @click="addRequirement" class="text-xs bg-indigo-50 text-indigo-600 px-2 py-1 rounded hover:bg-indigo-100">
                                            + Add Type
                                        </button>
                                    </div>

                                    <div class="space-y-3">
                                        <div v-for="(doc, index) in form.document_schema" :key="index" class="p-3 bg-gray-50 rounded-lg border border-gray-200 relative">
                                            <button type="button" @click="removeRequirement(index)" class="absolute -top-2 -right-2 bg-white border border-gray-200 text-gray-400 hover:text-red-500 rounded-full p-1 shadow-sm">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>

                                            <div class="space-y-2">
                                                <div>
                                                    <span class="text-[10px] uppercase font-bold text-gray-400">Display Label</span>
                                                    <Input
                                                            v-model="doc.label"
                                                            @input="suggestKey(index)"
                                                            placeholder="Label (e.g. Tech Spec)"
                                                            class="block w-full text-xs mt-0.5"
                                                        />
                                                </div>

                                                <div>
                                                    <span class="text-[10px] uppercase font-bold text-gray-400">System Key (slug)</span>
                                                    <Input
                                                        v-model="doc.key"
                                                        placeholder="e.g. intake"
                                                        class="block w-full text-xs mt-0.5 font-mono bg-white"
                                                    />
                                                </div>

                                                <div class="flex items-center justify-end">
                                                    <label class="flex items-center gap-2 cursor-pointer">
                                                        <input type="checkbox" v-model="doc.required" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                                        <span class="text-[10px] font-medium text-gray-600 uppercase">Required</span>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center gap-3 pt-2">
                                    <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700" :disabled="form.processing">
                                        {{ isEditing ? 'Update' : 'Save' }}
                                    </button>
                                    <button v-if="isEditing" type="button" @click="cancelEdit" class="text-sm text-gray-600 hover:underline">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="col-span-2">
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-200">
                            <div class="px-6 py-3 bg-white border-b border-gray-100 flex justify-between items-center">
                                <h3 class="text-sm font-semibold text-gray-700">Available Types</h3>
                            </div>
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Document Types</th>
                                        <th class="relative px-6 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="type in projectTypes" :key="type.id">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <div class="flex items-center">
                                                <component v-if="type.icon" :is="getIcon(type.icon)" class="w-4 h-4 mr-2 text-gray-400" />
                                                {{ type.name }}
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex flex-wrap gap-1">
                                                <span v-for="doc in type.document_schema" :key="doc.key" class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase border bg-gray-50 text-gray-500 border-gray-200">
                                                    {{ doc.label }} <span class="ml-1 text-[8px] opacity-60">({{ doc.key }})</span>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <button @click="edit(type)" class="text-indigo-600 hover:text-indigo-900 mr-4">Edit</button>
                                            <button @click="destroy(type.id)" class="text-red-600 hover:text-red-900">Delete</button>
                                        </td>
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

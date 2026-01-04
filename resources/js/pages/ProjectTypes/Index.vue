<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import ProjectTypeForm from './Partials/ProjectTypeForm.vue';
import {
    Plus, Edit2, Info, Code, Megaphone, Calendar, Layout,
    Database, Globe, Settings, PenTool, Rocket, Microscope,
    Briefcase, Music, Camera, Zap, Heart, Search, X
} from 'lucide-vue-next';

// UI Components
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
    Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle,
} from "@/components/ui/dialog";
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
    projectTypes: any[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Project Types', href: '#' },
];


// --- Search Logic ---
const searchQuery = ref('');

const filteredProjectTypes = computed(() => {
    if (!searchQuery.value) return props.projectTypes;

    const query = searchQuery.value.toLowerCase();
    return props.projectTypes.filter(type => {
        return type.name.toLowerCase().includes(query) ||
               type.document_schema?.some((doc: any) => doc.label.toLowerCase().includes(query));
    });
});

// --- Icon Library ---
const iconLibrary = [
    { name: 'Code', component: Code }, { name: 'Megaphone', component: Megaphone },
    { name: 'Calendar', component: Calendar }, { name: 'Layout', component: Layout },
    { name: 'Database', component: Database }, { name: 'Globe', component: Globe },
    { name: 'Settings', component: Settings }, { name: 'PenTool', component: PenTool },
    { name: 'Rocket', component: Rocket }, { name: 'Microscope', component: Microscope },
    { name: 'Briefcase', component: Briefcase }, { name: 'Music', component: Music },
    { name: 'Camera', component: Camera }, { name: 'Zap', component: Zap },
    { name: 'Heart', component: Heart }
];

const getIcon = (name: string) => iconLibrary.find(i => i.name === name)?.component || Info;

// --- Modal State ---
const isModalOpen = ref(false);
const activeType = ref<any | null>(null);

const openCreateModal = () => {
    activeType.value = null;
    isModalOpen.value = true;
};

const handleEdit = (type: any) => {
    activeType.value = type;
    isModalOpen.value = true;
};

const handleSuccess = () => {
    isModalOpen.value = false;
    activeType.value = null;
};
</script>

<template>
    <Head title="Project Types" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-7xl mx-auto w-full">

            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6 mb-8">
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white uppercase">
                        Project Types
                    </h1>
                    <p class="text-sm text-gray-500">
                        Define project categories, icons, and required document schemas.
                    </p>
                </div>

                <div class="flex flex-col sm:flex-row items-center gap-3 w-full lg:w-auto">
                    <div class="relative w-full sm:w-72">
                        <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                        <Input
                            v-model="searchQuery"
                            placeholder="Search types..."
                            class="pl-10 pr-10 rounded-xl bg-white border-gray-200 focus:ring-indigo-500 h-11"
                        />
                        <Button
                            v-if="searchQuery"
                            @click="searchQuery = ''"
                            class="absolute right-3 top-1/2 -translate-y-1/2 p-0.5 hover:bg-gray-100 rounded-full text-gray-400"
                        >
                            <X class="w-3.5 h-3.5" />
                        </Button>
                    </div>

                    <Button
                        @click="openCreateModal"
                        class="w-full sm:w-auto bg-indigo-600 hover:bg-indigo-700 text-white font-bold h-11 px-6 rounded-xl shadow-lg shadow-indigo-500/20 transition-all active:scale-95"
                    >
                        <Plus class="w-5 h-5 mr-2" />
                        New Type
                    </Button>
                </div>
            </div>

            <div class="grid gap-4">
                <div
                    v-for="type in filteredProjectTypes"
                    :key="type.id"
                    class="group flex items-center justify-between p-5 bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-2xl hover:border-indigo-500/50 hover:shadow-md transition-all duration-200"
                >
                    <div class="flex items-start gap-5 flex-1">
                        <div class="p-3.5 rounded-2xl bg-gray-50 dark:bg-gray-800 text-gray-400 group-hover:text-indigo-600 group-hover:bg-indigo-50 dark:group-hover:bg-indigo-500/10 transition-colors">
                            <component :is="getIcon(type.icon)" class="w-6 h-6" />
                        </div>

                        <div class="space-y-2">
                            <div class="flex items-center gap-3">
                                <h3 class="font-black text-gray-900 dark:text-white tracking-tight text-lg uppercase">
                                    {{ type.name }}
                                </h3>
                                <span v-if="type.projects_count > 0" class="text-[9px] font-black text-gray-400 uppercase tracking-widest bg-gray-100 dark:bg-gray-800 px-2 py-0.5 rounded-md">
                                    {{ type.projects_count }} {{ type.projects_count === 1 ? 'Project' : 'Projects' }}
                                </span>
                            </div>

                            <div class="flex flex-wrap gap-1.5">
                                <span
                                    v-for="doc in type.document_schema"
                                    :key="doc.key"
                                    class="px-2.5 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider border transition-colors"
                                    :class="doc.required
                                        ? 'bg-indigo-50 border-indigo-100 text-indigo-600'
                                        : 'bg-gray-50 border-gray-100 text-gray-400'
                                    "
                                >
                                    {{ doc.label }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button
                            variant="ghost"
                            size="icon"
                            @click="handleEdit(type)"
                            class="rounded-xl hover:text-indigo-600 hover:bg-indigo-50 transition-all"
                        >
                            <Edit2 class="w-4 h-4" />
                        </Button>
                    </div>
                </div>

                <div v-if="filteredProjectTypes.length === 0" class="py-20 text-center border-2 border-dashed border-gray-100 rounded-3xl">
                    <div class="bg-gray-50 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 text-gray-300">
                        <Search class="w-8 h-8" />
                    </div>
                    <h3 class="font-bold text-gray-900">No types found matching "{{ searchQuery }}"</h3>
                    <p class="text-sm text-gray-500">Try adjusting your search terms.</p>
                </div>
            </div>

            <Dialog v-model:open="isModalOpen">
                <DialogContent class="sm:max-w-[600px] max-h-[90vh] overflow-y-auto rounded-3xl">
                    <DialogHeader>
                        <DialogTitle class="text-xl font-black uppercase tracking-tight">
                            {{ activeType ? 'Edit' : 'New' }} Project Type
                        </DialogTitle>
                        <DialogDescription>
                            Configure the icons and required document schema for this type.
                        </DialogDescription>
                    </DialogHeader>

                    <ProjectTypeForm
                        :edit-data="activeType"
                        :icon-library="iconLibrary"
                        @success="handleSuccess"
                        @cancel="handleSuccess"
                    />
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>

<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import ProjectTypeForm from './Partials/ProjectTypeForm.vue';
import {
    Activity,
    Briefcase,
    Calendar,
    Camera,
    ChevronLeft,
    Code,
    Database,
    Globe,
    Heart,
    Info,
    Layout,
    Megaphone,
    Microscope,
    Music,
    PenTool,
    Rocket,
    Settings,
    Zap
} from 'lucide-vue-next';
import { type BreadcrumbItem } from '@/types';
import { toast } from "vue-sonner";
const activeTab = ref('general');

const tabs = [
    { id: 'general', label: 'General Info', icon: Info },
    { id: 'schema', label: 'Document Schema', icon: Database },
    { id: 'workflow', label: 'AI Pipeline', icon: Activity },
];

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

const props = defineProps<{
    projectType?: ProjectType; // Optional
    aiTemplates: { id: string, name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Project Protocols', href: '/project-types' },
    {
        title: props.projectType?.name ?? 'New Protocol',
        href: props.projectType?.id ? `/project-types/${props.projectType.id}` : '/project-types/create'
    }
];

const handleSuccess = () => {
    toast.success('Protocol updated', {
        description: 'Your changes have been saved to the database.',
    });
};


</script>

<template>
    <Head :title="`${projectType?.name ?? 'New'} - Protocol`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-5xl mx-auto w-full">
            <div class="mb-10">
                <Link href="/project-types" class="inline-flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 hover:text-indigo-600 transition-colors mb-6 group">
                    <ChevronLeft class="w-3 h-3 transition-transform group-hover:-translate-x-1" />
                    Back to Protocols
                </Link>

                <div class="flex flex-col md:flex-row md:items-end justify-between gap-6">
                    </div>
            </div>

            <div class="bg-white dark:bg-gray-950 rounded-[2.5rem] border border-gray-100 dark:border-gray-800 shadow-sm overflow-hidden">
                <div class="flex border-b border-gray-100 dark:border-gray-900 bg-gray-50/50 dark:bg-gray-900/50 px-8">
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        @click="activeTab = tab.id"
                        :class="[
                            'flex items-center gap-2 px-6 py-5 text-[10px] font-black uppercase tracking-widest transition-all relative',
                            activeTab === tab.id
                                ? 'text-indigo-600'
                                : 'text-gray-400 hover:text-gray-600'
                        ]"
                    >
                        <component :is="tab.icon" class="w-3.5 h-3.5" />
                        {{ tab.label }}
                        <div v-if="activeTab === tab.id" class="absolute bottom-0 left-0 right-0 h-0.5 bg-indigo-600"></div>
                    </button>
                </div>

                <div class="md:p-8">
                    <ProjectTypeForm
                        :active-tab="activeTab"
                        :edit-data="projectType"
                        :icon-library="iconLibrary"
                        :ai-templates="aiTemplates"
                        @success="handleSuccess"
                        @cancel="() => $inertia.visit('/project-types')"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>

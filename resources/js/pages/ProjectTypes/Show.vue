<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
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
    Settings2,
    Zap
} from 'lucide-vue-next';
import { type BreadcrumbItem } from '@/types';

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
    // Usually redirect back to index or show a "Saved" toast
};

const getIcon = (name?: string) => iconLibrary.find(i => i.name === name)?.component || Info;
</script>

<template>
    <Head :title="`${projectType?.name ?? 'New'} - Protocol`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-5xl mx-auto w-full">

            <div class="mb-10">
                <Link
                    href="/project-types"
                    class="inline-flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 hover:text-indigo-600 transition-colors mb-6 group"
                >
                    <ChevronLeft class="w-3 h-3 transition-transform group-hover:-translate-x-1" />
                    Back to Protocols
                </Link>

                <div class="flex flex-col md:flex-row md:items-end justify-between gap-6">
                    <div class="flex items-start gap-5">
                        <div class="h-16 w-16 rounded-3xl bg-indigo-600 flex items-center justify-center text-white shadow-xl shadow-indigo-500/20">
                            <component :is="getIcon(projectType?.icon)" class="w-8 h-8" />
                        </div>
                        <div>
                            <div class="flex items-center gap-3 mb-1">
                                <h1 class="text-3xl font-black tracking-tighter text-gray-900 dark:text-white uppercase">
                                    {{ projectType?.name ?? 'New Protocol' }}
                                </h1>
                                <span class="px-2 py-0.5 rounded-md bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 text-[10px] font-black uppercase tracking-tighter">
                                    Protocol
                                </span>
                            </div>
                            <p class="text-sm text-gray-500 font-medium italic">
                                Configure document relationships and automated AI intelligence for this project category.
                            </p>
                        </div>
                    </div>

                    <div class="flex gap-4 border-l border-gray-100 dark:border-gray-800 pl-6 hidden lg:flex">
                        <div class="text-center">
                            <p class="text-[9px] font-black uppercase text-gray-400 tracking-widest mb-1">Documents</p>
                            <div class="flex items-center gap-1 justify-center text-gray-900 dark:text-white font-bold">
                                <Database class="w-3 h-3 text-indigo-500" />
                                {{ projectType?.document_schema?.length ?? 0 }}
                            </div>
                        </div>
                        <div class="w-px h-8 bg-gray-100 dark:bg-gray-800"></div>
                        <div class="text-center">
                            <p class="text-[9px] font-black uppercase text-gray-400 tracking-widest mb-1">AI Steps</p>
                            <div class="flex items-center gap-1 justify-center text-gray-900 dark:text-white font-bold">
                                <Activity class="w-3 h-3 text-indigo-500" />
                                {{ projectType?.workflow?.length ?? 0 }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-950 rounded-[2.5rem] border border-gray-100 dark:border-gray-800 shadow-sm overflow-hidden">
                <div class="p-8 md:p-12">
                    <div class="flex items-center gap-3 mb-10 pb-6 border-b border-gray-50 dark:border-gray-900">
                        <Settings2 class="w-5 h-5 text-indigo-500" />
                        <h2 class="text-sm font-black uppercase tracking-[0.2em] text-gray-900 dark:text-white">
                            Engine Configuration
                        </h2>
                    </div>

                    <ProjectTypeForm
                        :edit-data="projectType"
                        :icon-library="iconLibrary"
                        :ai-templates="aiTemplates"
                        @success="handleSuccess"
                        @cancel="() => $inertia.visit('/project-types')"
                    />
                </div>
            </div>

            <div class="mt-12 p-8 border-2 border-dashed border-gray-100 dark:border-gray-800 rounded-[2.5rem] flex flex-col md:flex-row items-center justify-between gap-6">
                <div class="text-center md:text-left">
                    <h4 class="text-xs font-black uppercase text-gray-400 tracking-widest mb-1">Protocol Usage</h4>
                    <p class="text-[11px] text-gray-500 italic font-medium">Changes here will affect all active and future projects using this protocol.</p>
                </div>
                <div class="flex gap-4">
                    </div>
            </div>
        </div>
    </AppLayout>
</template>

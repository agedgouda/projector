<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import ProjectTypeForm from './Partials/ProjectTypeForm.vue';
import {
    Briefcase,
    Calendar,
    Camera,
    ChevronLeft,
    Code,
    Database,
    Globe,
    Heart,
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
        <div class="max-w-7xl px-8 py-10">
            <div class="mb-10">
                <Link href="/project-types" class="inline-flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 hover:text-indigo-600 transition-colors mb-6 group">
                    <ChevronLeft class="w-3 h-3 transition-transform group-hover:-translate-x-1" />
                    Back to Protocols
                </Link>
            </div>

            <ProjectTypeForm
                :edit-data="projectType"
                :icon-library="iconLibrary"
                :ai-templates="aiTemplates"
                @success="handleSuccess"
                @cancel="() => $inertia.visit('/project-types')"
            />
            </div>
    </AppLayout>
</template>

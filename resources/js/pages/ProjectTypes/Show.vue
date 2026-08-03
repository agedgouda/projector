<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
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
    Zap,
} from 'lucide-vue-next';
import { toast } from 'vue-sonner';
import ProjectTypeForm from './Partials/ProjectTypeForm.vue';

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
    { name: 'Heart', component: Heart },
];

const props = defineProps<{
    projectType?: ProjectType;
    template?: ProjectType;
    aiTemplates: { id: string; name: string }[];
    organizations?: { id: string; name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pipelines', href: '/project-types' },
    {
        title: props.projectType?.name ?? 'New Protocol',
        href: props.projectType?.id
            ? `/project-types/${props.projectType.id}`
            : '/project-types/create',
    },
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
        <div class="w-full p-6">
            <div class="mb-10">
                <Link
                    href="/project-types"
                    class="group mb-6 inline-flex items-center gap-2 text-[10px] font-black tracking-[0.2em] text-gray-400 uppercase transition-colors hover:text-projector-primary-600"
                >
                    <ChevronLeft
                        class="h-3 w-3 transition-transform group-hover:-translate-x-1"
                    />
                    Back to Pipelines
                </Link>
            </div>

            <ProjectTypeForm
                :edit-data="projectType"
                :template="template"
                :icon-library="iconLibrary"
                :ai-templates="aiTemplates"
                :organizations="organizations ?? []"
                @success="handleSuccess"
                @cancel="() => $inertia.visit('/project-types')"
            />
        </div>
    </AppLayout>
</template>

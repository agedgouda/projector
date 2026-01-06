<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import ResourceList from '@/components/ResourceList.vue';
import ResourceCard from '@/components/ResourceCard.vue';
import {
    ArrowRight, Plus, Edit2, Info, Code, Megaphone, Calendar, Layout,
    Database, Globe, Settings, PenTool, Rocket, Microscope,
    Briefcase, Music, Camera, Zap, Heart, Search,
    Layers
} from 'lucide-vue-next';

// UI Components
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
    projectTypes: any[];
    aiTemplates: { id: string, name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Project Protocols', href: '/project-types' }];

// --- Search Logic ---
const searchQuery = ref('');
const filteredProjectTypes = computed(() => {
    let list = props.projectTypes;
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        list = list.filter(type =>
            type.name.toLowerCase().includes(query) ||
            type.document_schema?.some((doc: any) => doc.label.toLowerCase().includes(query))
        );
    }
    return list.map(t => ({ ...t, domId: `type-${t.id}` }));
});

// --- Icon Mapping ---
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
</script>

<template>
    <Head title="Project Protocols" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-7xl mx-auto w-full">

            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6 mb-12">
                <div>
                    <h1 class="text-2xl font-black tracking-tighter text-gray-900 dark:text-white uppercase flex items-center gap-3">
                        <Layers class="w-7 h-7 text-indigo-600" />
                        Project Protocols
                    </h1>
                    <p class="text-sm text-gray-500 font-medium">Define the document structures and AI logic for your project types.</p>
                </div>

                <div class="flex flex-col sm:flex-row items-center gap-3 w-full lg:w-auto">
                    <div class="relative w-full sm:w-72 group">
                        <Search class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 group-focus-within:text-indigo-500 transition-colors" />
                        <Input v-model="searchQuery" placeholder="Filter protocols..." class="pl-11 pr-10 rounded-2xl bg-white dark:bg-gray-900 h-12 border-gray-200 dark:border-gray-800" />
                    </div>

                    <Link href="/project-types/create">
                        <Button class="w-full sm:w-auto font-black h-12 px-8 rounded-2xl shadow-lg bg-indigo-600 hover:bg-indigo-700 text-white uppercase text-[10px] tracking-widest shadow-indigo-500/20">
                            <Plus class="w-4 h-4 mr-2" />
                            New Protocol
                        </Button>
                    </Link>
                </div>
            </div>

            <div class="relative">
                <div v-if="filteredProjectTypes.length === 0" class="py-24 text-center border-2 border-dashed border-gray-100 dark:border-gray-800 rounded-[3rem]">
                    <Search class="w-10 h-10 mx-auto mb-4 text-gray-200" />
                    <h3 class="font-black text-gray-400 uppercase tracking-widest text-xs">No matching protocols found</h3>
                </div>

                <ResourceList :items="filteredProjectTypes">
                    <template #default="{ item: type }">
                        <ResourceCard
                            :title="type.name"
                            :pill-text="`${type.projects_count || 0} Projects`"
                            :show-delete="false"
                        >
                            <template #icon>
                                <div class="p-4 rounded-2xl bg-gray-50 dark:bg-gray-900 text-gray-400 group-hover:bg-indigo-50 dark:group-hover:bg-indigo-500/10 group-hover:text-indigo-600 transition-all">
                                    <component :is="getIcon(type.icon)" class="w-6 h-6" />
                                </div>
                            </template>

                            <div class="space-y-6 mt-4">
                                <div>
                                    <p class="text-[8px] font-black uppercase tracking-[0.2em] text-gray-400 mb-2 px-1">Structure</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <span
                                            v-for="doc in type.document_schema"
                                            :key="doc.key"
                                            class="px-2.5 py-1 rounded-lg text-[10px] font-bold border transition-all flex items-center gap-1.5"
                                            :class="doc.required
                                                ? 'bg-indigo-50/50 border-indigo-100 text-indigo-700 dark:bg-indigo-500/5 dark:border-indigo-500/20 dark:text-indigo-400'
                                                : 'bg-white border-gray-100 text-gray-500 dark:bg-gray-950 dark:border-gray-800'"
                                        >
                                            <div v-if="doc.required" class="w-1 h-1 rounded-full bg-indigo-500 shadow-[0_0_5px_rgba(99,102,241,0.5)]"></div>
                                            {{ doc.label }}
                                        </span>
                                    </div>
                                </div>

                                <div v-if="type.workflow?.length">
                                    <p class="text-[8px] font-black uppercase tracking-[0.2em] text-gray-400 mb-2 px-1">Automated Pipeline</p>
                                    <div class="flex flex-wrap gap-y-2 gap-x-4 items-center">
                                        <div
                                            v-for="(step, idx) in type.workflow"
                                            :key="idx"
                                            class="flex items-center gap-2 group/step"
                                        >
                                            <span class="text-[10px] font-black text-gray-600 dark:text-gray-300 uppercase tracking-tighter">{{ step.from_key }}</span>
                                            <div class="flex flex-col items-center">
                                                <Zap class="w-2.5 h-2.5 text-amber-500 mb-0.5" v-if="step.ai_template_id" />
                                                <ArrowRight class="h-3 text-gray-500" />
                                            </div>
                                            <span class="text-[10px] font-black text-indigo-600 dark:text-indigo-400 uppercase tracking-tighter">{{ step.to_key }}</span>

                                            <div v-if="Number(idx) < type.workflow.length - 1" class="ml-2 w-1 h-1 rounded-full bg-gray-200 dark:bg-gray-800"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <template #actions>
                                <Link :href="`/project-types/${type.id}/edit`" class="p-3 rounded-xl text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-500/10 transition-all">
                                    <Edit2 class="w-5 h-5" />
                                </Link>
                            </template>
                        </ResourceCard>
                    </template>
                </ResourceList>
            </div>
        </div>
    </AppLayout>
</template>

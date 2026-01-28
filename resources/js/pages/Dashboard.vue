<script setup lang="ts">
import { computed } from 'vue';
import { router, Head, usePage } from '@inertiajs/vue3';
import {
    Folder,
    ChevronDown,
    Plus,
    Kanban as KanbanIcon,
    Settings2,
    Clock
} from 'lucide-vue-next';

// Layouts & UI
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

// Wayfinder
import projectRoutes from '@/routes/projects';
import projectTypeRoutes from '@/routes/project-types';

// Standard module-level export
import { type BreadcrumbItem } from '@/types';

/* ---------------------------
   1. Typed Page Props
---------------------------- */
// This solves the 'unknown' error by casting the Inertia page object
// to the AppPageProps interface you defined in your global file.
const page = usePage<AppPageProps>();

const props = withDefaults(defineProps<{
    projects: Project[]; // Global interface
    currentProject: Project | null;
    kanbanData: Record<string, ProjectDocument[]>;
}>(), {
    projects: () => [],
    currentProject: null,
    kanbanData: () => ({})
});

/* ---------------------------
   2. Computed State
---------------------------- */
const isAdmin = computed(() => {
    const roles = page.props.auth.user.roles;
    // Check if any object in the array has the name 'admin'
    return roles.some((role: any) => role.name === 'admin');
});

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Dashboard', href: '/dashboard' },
    { title: props.currentProject?.name ?? 'Select Project', href: '' }
]);

const activeColumns = computed(() => {
    return props.currentProject?.type?.document_schema?.filter(s => s.is_task) || [];
});

/* ---------------------------
   3. Helpers
---------------------------- */
const getInitials = (user: User) => {
    const first = user.first_name?.charAt(0) || '';
    const last = user.last_name?.charAt(0) || '';
    return (first + last).toUpperCase() || user.name.charAt(0).toUpperCase();
};

const getAvatarColor = (userId: number) => {
    const variants = [
        'bg-indigo-50 text-indigo-700 border-indigo-100',
        'bg-emerald-50 text-emerald-700 border-emerald-100',
        'bg-amber-50 text-amber-700 border-amber-100',
        'bg-rose-50 text-rose-700 border-rose-100',
        'bg-sky-50 text-sky-700 border-sky-100'
    ];
    return variants[userId % variants.length];
};

const formatTimeAgo = (dateString: string) => {
    const date = new Date(dateString);
    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
};

/* ---------------------------
   4. Actions
---------------------------- */
const switchProject = (projectId: string) => {
    router.get('/dashboard', { project: projectId }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const handleCreateNew = (typeKey: string) => {
    if (!props.currentProject) return;
    router.visit(`${projectRoutes.documents.create(props.currentProject.id).url}?type=${typeKey}`);
};

const openProtocolSettings = () => {
    const typeId = props.currentProject?.type?.id;
    if (typeId) {
        router.visit(projectTypeRoutes.edit(typeId).url);
    }
};
</script>

<template>
    <Head :title="currentProject ? `${currentProject.name} - Dashboard` : 'Dashboard'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-8 max-w-[1600px] mx-auto space-y-8">

            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <DropdownMenu v-if="projects.length > 0">
                        <DropdownMenuTrigger as-child>
                            <Button variant="ghost" class="h-14 px-5 rounded-2xl bg-white border border-gray-100 flex items-center gap-4 hover:bg-gray-50 transition-all shadow-sm">
                                <div class="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center text-white">
                                    <Folder class="w-5 h-5" />
                                </div>
                                <div class="text-left">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400 leading-none mb-1.5">Active Project</p>
                                    <p class="text-base font-bold text-gray-900">{{ currentProject?.name ?? 'Select Project' }}</p>
                                </div>
                                <ChevronDown class="w-4 h-4 text-gray-300 ml-2" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" class="w-72 rounded-xl p-2">
                            <DropdownMenuItem v-for="p in projects" :key="p.id" @click="switchProject(p.id)" class="p-3 cursor-pointer rounded-lg">
                                <span class="font-bold text-sm">{{ p.name }}</span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                 <Button
                    v-if="isAdmin"
                    @click="openProtocolSettings"
                    variant="outline"
                    class="h-10 rounded-xl border-gray-200 text-[10px] font-black uppercase tracking-widest text-gray-500 gap-2 hover:bg-white hover:border-indigo-200 transition-all shadow-sm"
                >
                    <Settings2 class="w-3.5 h-3.5" /> Protocol Settings
                </Button>
            </div>

            <div v-if="currentProject" class="flex gap-6 overflow-x-auto pb-6 scrollbar-hide">
                <div v-for="column in activeColumns" :key="column.key" class="flex-1 min-w-[340px] max-w-[420px] flex flex-col gap-4">

                    <div class="flex items-center justify-between px-2">
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] font-black uppercase tracking-widest text-gray-400">{{ column.label }}</span>
                            <span class="bg-gray-100 text-[10px] px-2 py-0.5 rounded-full font-bold text-gray-500">
                                {{ kanbanData[column.key]?.length || 0 }}
                            </span>
                        </div>
                    </div>

                    <div class="flex-1 bg-gray-50/50 rounded-[2.5rem] border border-dashed border-gray-200 p-4 space-y-4 min-h-[500px]">
                        <div v-for="doc in kanbanData[column.key]" :key="doc.id"
                             @click="router.visit(projectRoutes.documents.show({ project: currentProject.id, document: doc.id }).url)"
                             class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm hover:border-indigo-200 hover:shadow-md cursor-pointer group transition-all"
                        >
                            <h4 class="text-sm font-bold text-gray-900 group-hover:text-indigo-600 transition-colors mb-5 line-clamp-2 leading-snug">
                                {{ doc.name }}
                            </h4>

                            <div class="flex items-center justify-between">
                                <div class="flex -space-x-2">
                                    <div v-if="doc.assignee"
                                         class="w-8 h-8 rounded-full border-2 border-white flex items-center justify-center shadow-sm"
                                         :class="getAvatarColor(doc.assignee.id)"
                                         :title="doc.assignee.name"
                                    >
                                        <span class="text-[10px] font-black tracking-tighter">{{ getInitials(doc.assignee) }}</span>
                                    </div>
                                    <div v-else class="w-8 h-8 rounded-full border-2 border-dashed border-gray-100 bg-white flex items-center justify-center">
                                        <Plus class="w-3 h-3 text-gray-200" />
                                    </div>
                                </div>

                                <div class="flex items-center gap-4">
                                    <div class="flex items-center gap-1.5 text-gray-400">
                                        <Clock class="w-3 h-3" />
                                        <span class="text-[9px] font-bold uppercase tracking-tighter">{{ formatTimeAgo(doc.updated_at) }}</span>
                                    </div>
                                    <div class="flex items-center gap-2 bg-gray-50 px-2 py-1 rounded-lg border border-gray-100">
                                        <div class="h-1.5 w-1.5 rounded-full" :class="doc.status === 'done' ? 'bg-emerald-500' : 'bg-indigo-500'"></div>
                                        <span class="text-[9px] font-black uppercase text-gray-500 tracking-widest">{{ doc.status }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <Button
                            variant="ghost"
                            @click="handleCreateNew(column.key)"
                            class="w-full h-14 border border-dashed border-gray-200 rounded-2xl text-[9px] font-black uppercase tracking-widest text-gray-400 hover:bg-white hover:text-indigo-600 hover:border-indigo-100 transition-all bg-transparent"
                        >
                            <Plus class="w-4 h-4 mr-2" /> New {{ column.plural_label || column.label }}
                        </Button>
                    </div>
                </div>
            </div>

            <div v-else class="min-h-[500px] flex flex-col items-center justify-center border-2 border-dashed border-gray-100 rounded-[3.5rem] bg-gray-50/30">
                <div class="w-16 h-16 bg-white rounded-2xl flex items-center justify-center shadow-sm border border-gray-100 mb-6">
                    <KanbanIcon class="w-8 h-8 text-gray-200" />
                </div>
                <p class="text-gray-400 font-bold uppercase tracking-[0.2em] text-[10px]">Select a project protocol to begin</p>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.scrollbar-hide::-webkit-scrollbar { display: none; }
.scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
</style>

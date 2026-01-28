<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, Head, usePage } from '@inertiajs/vue3';
import {
    Folder,
    ChevronDown,
    Plus,
    Kanban as KanbanIcon,
    Settings2,
    Calendar
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
import draggable from 'vuedraggable';
import { toast } from 'vue-sonner';
import DocumentDetailSheet from './Partials/DocumentDetailSheet.vue';

// Wayfinder & Constants
import projectRoutes from '@/routes/projects';
import projectTypeRoutes from '@/routes/project-types';
import { STATUS_LABELS, statusDotClasses, priorityClasses } from '@/lib/constants';

// Standard module-level export
import { type BreadcrumbItem } from '@/types';

const page = usePage<AppPageProps>();

const props = withDefaults(defineProps<{
    projects: Project[];
    currentProject: Project | null;
    kanbanData: Record<string, ProjectDocument[]>;
}>(), {
    projects: () => [],
    currentProject: null,
    kanbanData: () => ({})
});

interface KanbanDragEvent<T> {
    added?: {
        element: T;
        newIndex: number;
    };
    removed?: {
        element: T;
        oldIndex: number;
    };
    moved?: {
        element: T;
        newIndex: number;
        oldIndex: number;
    };
}
/* ---------------------------
   1. Board Configuration
---------------------------- */
const columnStatuses = ['backlog', 'todo', 'in_progress', 'done'] as const;

const workflowRows = computed(() => {
    return props.currentProject?.type?.document_schema?.filter(s => s.is_task) || [];
});

const isAdmin = computed(() => {
    const roles = page.props.auth.user.roles;
    return roles.some((role: any) => role.name === 'admin');
});

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Dashboard', href: '/dashboard' },
    { title: props.currentProject?.name ?? 'Select Project', href: '' }
]);

/* ---------------------------
   2. Helpers
---------------------------- */
const getTasksByRowAndStatus = (typeKey: string, status: string) => {
    const tasksForType = props.kanbanData[typeKey] || [];
    return tasksForType.filter(doc => {
        const currentStatus = (doc.task_status || doc.status || 'todo').toLowerCase();
        return currentStatus === status.toLowerCase();
    });
};

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

/* ---------------------------
   3. Actions
---------------------------- */
const switchProject = (projectId: string) => {
    router.get('/dashboard', { project: projectId }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const handleCreateNew = (typeKey: string) => {
    if (!props.currentProject) return;

    const route = projectRoutes.documents.create({
        project: props.currentProject.id
    });

    router.visit(route.url, {
        data: {
            type: typeKey
        }
    });
};

const openProtocolSettings = () => {
    const typeId = props.currentProject?.type?.id;
    if (typeId) {
        router.visit(projectTypeRoutes.edit(typeId).url);
    }
};

/* ---------------------------
   4. Drag & Drop & Editing
---------------------------- */
const selectedDocument = ref<ProjectDocument | null>(null);
const isSheetOpen = ref(false);

watch(() => props.kanbanData, (newData) => {
    if (!selectedDocument.value) return;
    for (const key in newData) {
        const found = newData[key].find(doc => doc.id === selectedDocument.value?.id);
        if (found) {
            selectedDocument.value = found;
            break;
        }
    }
}, { deep: true });

const openDetail = (doc: ProjectDocument) => {
    selectedDocument.value = doc;
    isSheetOpen.value = true;
};

const updateAttribute = (documentId: string, data: Record<string, any>, successMessage?: string) => {
    if (!props.currentProject) return;

    router.patch(
        projectRoutes.documents.update({
            project: props.currentProject.id,
            document: documentId
        }).url,
        data,
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                if (successMessage) toast.success(successMessage);
            },
            onError: (errors) => {
                toast.error('Failed to save changes');
                console.error("Update error:", errors);
            }
        }
    );
};

const onDragChange = (evt: KanbanDragEvent<ProjectDocument>, newStatus: string) => {
    if (!props.currentProject || !evt.added) return;

    const doc = evt.added.element;

    updateAttribute(String(doc.id), {
        status: newStatus,
        task_status: newStatus
    }, `Moved "${doc.name}" to ${STATUS_LABELS[newStatus]}`);
};

const getColumnTaskCount = (status: string) => {
    return Object.values(props.kanbanData).reduce((acc, tasks) => {
        return acc + tasks.filter(doc => (doc.task_status || doc.status || 'todo').toLowerCase() === status.toLowerCase()).length;
    }, 0);
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

            <div v-if="currentProject" class="space-y-5">
                <div class="grid grid-cols-4 gap-8 px-4 sticky top-0 bg-white/90 backdrop-blur-md z-20 py-0 b-0 border-b border-gray-100/50">
                    <div v-for="status in columnStatuses" :key="status" class="flex items-center justify-center gap-3 py-4">
                        <div :class="['h-2 w-2 rounded-full', statusDotClasses[status]]"></div>
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">
                                {{ STATUS_LABELS[status] }}
                            </span>
                            <span class="flex items-center justify-center bg-gray-100 text-gray-500 text-[9px] font-bold px-1.5 py-0.5 rounded-full min-w-[20px]">
                                {{ getColumnTaskCount(status) }}
                            </span>
                        </div>
                    </div>
                </div>

                <div v-for="row in workflowRows" :key="row.key" class="space-y-4">
                    <div class="flex items-center gap-4 px-2">
                        <h3 class="text-[9px] font-black uppercase tracking-widest text-indigo-900 bg-indigo-50/80 px-2 py-1 rounded-md border border-indigo-100/50">
                            {{ row.label }}
                        </h3>
                        <div class="h-px flex-1 bg-gradient-to-r from-indigo-100/50 to-transparent"></div>
                    </div>

                    <div class="grid grid-cols-4 gap-8">
                        <div v-for="status in columnStatuses" :key="status"
                             class="flex flex-col gap-4 min-h-[160px] bg-gray-50/40 rounded-[2rem] border border-dashed border-gray-200/60 p-4 relative"
                        >
                            <draggable
                                :model-value="getTasksByRowAndStatus(row.key, status)"
                                :group="{ name: `tasks-${row.key}` }"
                                item-key="id"
                                class="flex-1 space-y-4 min-h-[100px]"
                                ghost-class="opacity-50"
                                @change="onDragChange($event, status)"
                            >
                                <template #item="{ element: doc }">
                                    <div
                                        @click="openDetail(doc)"
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
                                                <div v-else class="w-8 h-8 rounded-full border-2 border-dashed border-gray-200 bg-white flex items-center justify-center">
                                                    <Plus class="w-3 h-3 text-gray-200" />
                                                </div>
                                            </div>

                                            <div class="flex items-center gap-3">
                                                <div v-if="doc.due_at" class="flex items-center gap-1 text-gray-400">
                                                    <Calendar class="w-3 h-3" />
                                                    <span class="text-[9px] font-black uppercase tracking-tighter">
                                                        {{ new Date(doc.due_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) }}
                                                    </span>
                                                </div>

                                                <div v-if="doc.priority" :class="['px-2 py-0.5 rounded text-[8px] font-black uppercase border', priorityClasses[doc.priority]]">
                                                    {{ doc.priority }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </draggable>

                            <Button
                                v-if="status === 'backlog' || status === 'todo'"
                                variant="ghost"
                                @click="handleCreateNew(row.key)"
                                class="w-full h-14 border border-dashed border-gray-200/80 rounded-2xl text-[9px] font-black uppercase tracking-widest text-gray-300 hover:text-indigo-600 hover:bg-white transition-all bg-transparent shadow-none mt-auto"
                            >
                                <Plus class="w-4 h-4 mr-2" /> New {{ row.label }}
                            </Button>
                        </div>
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

        <DocumentDetailSheet
            v-if="selectedDocument"
            v-model:open="isSheetOpen"
            :document="(selectedDocument as ProjectDocument)"
            @update-attribute="(attr, val) => updateAttribute(String(selectedDocument!.id), { [attr]: val })"
        />
    </AppLayout>
</template>

<style scoped>
.scrollbar-hide::-webkit-scrollbar { display: none; }
.scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
</style>

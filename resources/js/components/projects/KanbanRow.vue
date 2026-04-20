<script setup lang="ts">
import KanbanColumn from './KanbanColumn.vue';
import { KANBAN_UI } from '@/lib/kanban-theme';
import { Link } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';
import projectRoutes from '@/routes/projects/index';

defineProps<{
    row: any;
    columnStatuses: TaskStatus[];
    gridStyle: Record<string, string>;
    getTasks: (rowKey: string, status: TaskStatus) => ProjectDocument[];
    onDrag: (evt: any, status: TaskStatus) => void;
    onOpen: (doc: ProjectDocument) => void;
    onCreate: (rowKey: string) => void;
    canViewProjectDetails?: boolean;
}>();
</script>

<template>
    <div class="space-y-4">
        <div v-if="row.label" class="flex items-center gap-4 px-2">
            <h3 :class="[KANBAN_UI.label, 'text-indigo-900 bg-indigo-50/80 px-2 py-1 rounded-md border border-indigo-100/50']">
                {{ row.label }}
            </h3>
            <div class="h-px flex-1 bg-gradient-to-r from-indigo-100/50 to-transparent"></div>
            <Link
                v-if="canViewProjectDetails"
                :href="projectRoutes.show.url(row.key)"
                class="flex items-center gap-1.5 text-[10px] font-black uppercase tracking-widest text-indigo-600 hover:text-indigo-800 transition-colors shrink-0"
            >
                <ExternalLink class="w-3 h-3" />
                View Details
            </Link>
        </div>

        <div class="grid gap-8" :style="KANBAN_UI.gridContainer(columnStatuses.length)">
            <template v-for="status in columnStatuses" :key="status">
                <KanbanColumn
                    :status="status"
                    :tasks="getTasks(row.key, status)"
                    :row-label="row.label"
                    @drag="(evt) => onDrag(evt, status)"
                    @open="onOpen"
                    @create="onCreate(row.key)"
                />
            </template>
        </div>
    </div>
</template>

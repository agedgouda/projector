<script setup lang="ts">
import KanbanColumn from './KanbanColumn.vue';
import KanbanHeader from './KanbanHeader.vue';
import { KANBAN_UI } from '@/lib/kanban-theme';
import type { AssigneeOption } from '@/lib/assignees';
import { Link } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';
import projectRoutes from '@/routes/projects/index';

const props = defineProps<{
    row: any;
    columns: KanbanColumnDef[];
    getTasks: (rowKey: string, status: TaskStatus) => ProjectDocument[];
    getTaskCount: (rowKey: string, status: TaskStatus) => number;
    onDrag: (evt: any, column: KanbanColumnDef) => void;
    onOpen: (doc: ProjectDocument) => void;
    onCreate: (rowKey: string) => void;
    onUpdateAttribute: (docId: string | number, field: string, value: string | number | null) => void;
    onUpdateTags: (docId: string | number, categories: CategoryDef[]) => void;
    canViewProjectDetails?: boolean;
    currentProject?: Project | null;
    canManage?: boolean;
    projectsById: Map<string, Project>;
    assigneeOptionsByProjectId: Map<string, AssigneeOption[]>;
    matchesFilters: (doc: ProjectDocument) => boolean;
}>();

const getRowCount = (status: TaskStatus) => props.getTaskCount(props.row.key, status);
</script>

<template>
    <div class="space-y-4">
        <div v-if="row.label" class="flex items-center gap-4 px-2">
            <!-- Explicit ?tab=tasks on both links below — the project page otherwise falls
                 back to whatever tab a `last_active_tab` cookie remembers from a previous
                 visit (see ProjectController::show()), which could land the user somewhere
                 other than Tasks. Coming from the Dashboard's kanban board, landing on Tasks
                 specifically is the whole point of the click. -->
            <Link
                v-if="canViewProjectDetails"
                :href="projectRoutes.show.url(row.key, { query: { tab: 'tasks' } })"
                class="contents"
            >
                <h3
                    :class="[
                        KANBAN_UI.label,
                        'text-projector-primary-900 bg-projector-primary-50/80 px-2 py-1 rounded-md border border-projector-primary-100/50 hover:bg-projector-primary-100/80 hover:border-projector-primary-200 transition-colors cursor-pointer',
                    ]"
                >
                    {{ row.label }}
                </h3>
            </Link>
            <h3
                v-else
                :class="[KANBAN_UI.label, 'text-projector-primary-900 bg-projector-primary-50/80 px-2 py-1 rounded-md border border-projector-primary-100/50']"
            >
                {{ row.label }}
            </h3>
            <div class="h-px flex-1 bg-gradient-to-r from-projector-primary-100/50 to-transparent"></div>
            <Link
                v-if="canViewProjectDetails"
                :href="projectRoutes.show.url(row.key, { query: { tab: 'tasks' } })"
                class="flex items-center gap-1.5 text-[10px] font-black uppercase tracking-widest text-projector-primary-600 hover:text-projector-primary-800 transition-colors shrink-0"
            >
                <ExternalLink class="w-3 h-3" />
                View Details
            </Link>
        </div>

        <!-- Columns have a 240px floor (see gridContainer); once columnCount * 240px no
             longer fits, this scrolls horizontally instead of squeezing columns further.
             w-fit + min-w-full lets the row's true (possibly wider-than-viewport) content
             width bubble up so the overflow-x-auto below actually has something to scroll,
             while still filling the full row width when there's room to spare. -->
        <div class="overflow-x-auto overflow-y-visible">
            <div class="w-fit min-w-full space-y-4">
                <KanbanHeader
                    :columns="columns"
                    :get-count="getRowCount"
                    :current-project="currentProject"
                    :can-manage="canManage"
                />

                <div class="grid gap-8" :style="KANBAN_UI.gridContainer(columns.length)">
                    <template v-for="column in columns" :key="column.key">
                        <KanbanColumn
                            :column="column"
                            :tasks="getTasks(row.key, column.key)"
                            :row-label="row.label"
                            :projects-by-id="projectsById"
                            :assignee-options-by-project-id="assigneeOptionsByProjectId"
                            :current-project="currentProject"
                            :matches-filters="matchesFilters"
                            @drag="(evt) => onDrag(evt, column)"
                            @open="onOpen"
                            @create="onCreate(row.key)"
                            @update-attribute="onUpdateAttribute"
                            @update-tags="onUpdateTags"
                        />
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>

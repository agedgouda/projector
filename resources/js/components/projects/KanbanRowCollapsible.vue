<script setup lang="ts">
import { kanbanDotClasses } from '@/lib/constants';
import { KANBAN_UI } from '@/lib/kanban-theme';
import projectRoutes from '@/routes/projects/index';
import { Link } from '@inertiajs/vue3';
import { ChevronDown, ExternalLink } from 'lucide-vue-next';
import { ref } from 'vue';
import KanbanColumn from './KanbanColumn.vue';
import KanbanHeader from './KanbanHeader.vue';

// Dashboard2 demo of "collapse each project into a summary card" (option 2 from the dashboard
// redesign discussion) — a sibling to KanbanRow.vue rather than a modification of it, so the
// real /dashboard is untouched while this is evaluated. Collapsed by default: shows the project
// name, a per-column count badge, and a "View Project" link. Expanding (via the chevron) reveals
// the exact same header+grid KanbanRow.vue always shows, reusing KanbanHeader/KanbanColumn as-is
// so the actual board content doesn't drift between the two.
const props = defineProps<{
    row: any;
    columns: KanbanColumnDef[];
    getTasks: (rowKey: string, status: TaskStatus) => ProjectDocument[];
    onDrag: (evt: any, column: KanbanColumnDef) => void;
    onOpen: (doc: ProjectDocument) => void;
    onCreate: (rowKey: string) => void;
    onUpdateAttribute: (
        docId: string | number,
        field: string,
        value: string | number | null,
    ) => void;
    canViewProjectDetails?: boolean;
    currentProject?: Project | null;
    canManage?: boolean;
}>();

const getRowCount = (status: TaskStatus) =>
    props.getTasks(props.row.key, status).length;

const totalCount = () =>
    props.columns.reduce((sum, column) => sum + getRowCount(column.key), 0);

const expanded = ref(false);
</script>

<template>
    <div
        class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900/40"
    >
        <button
            type="button"
            class="flex w-full items-center gap-4 px-4 py-3 text-left"
            @click="expanded = !expanded"
        >
            <ChevronDown
                class="h-4 w-4 shrink-0 text-gray-400 transition-transform duration-200"
                :class="{ '-rotate-90': !expanded }"
            />

            <h3
                :class="[
                    KANBAN_UI.label,
                    'shrink-0 text-projector-primary-900',
                ]"
            >
                {{ row.label }}
            </h3>

            <div
                class="flex flex-1 flex-wrap items-center gap-3 overflow-hidden"
            >
                <span
                    v-for="column in columns"
                    :key="column.key"
                    class="flex items-center gap-1.5 text-[11px] font-bold text-gray-500 dark:text-gray-400"
                >
                    <span
                        class="h-1.5 w-1.5 shrink-0 rounded-full"
                        :class="kanbanDotClasses[column.color ?? 'slate']"
                    ></span>
                    {{ getRowCount(column.key) }} {{ column.label }}
                </span>

                <span
                    v-if="totalCount() === 0"
                    class="text-[11px] text-gray-400"
                    >No tasks</span
                >
            </div>

            <Link
                v-if="canViewProjectDetails"
                :href="
                    projectRoutes.show.url(row.key, { query: { tab: 'tasks' } })
                "
                class="flex shrink-0 items-center gap-1.5 text-[10px] font-black tracking-widest text-projector-primary-600 uppercase transition-colors hover:text-projector-primary-800"
                @click.stop
            >
                <ExternalLink class="h-3 w-3" />
                View Project
            </Link>
        </button>

        <div
            v-if="expanded"
            class="border-t border-gray-100 px-4 pt-4 pb-6 dark:border-gray-800"
        >
            <div class="overflow-x-auto overflow-y-visible">
                <div class="w-fit min-w-full space-y-4">
                    <KanbanHeader
                        :columns="columns"
                        :get-count="getRowCount"
                        :current-project="currentProject"
                        :can-manage="canManage"
                    />

                    <div
                        class="grid gap-8"
                        :style="KANBAN_UI.gridContainer(columns.length)"
                    >
                        <template v-for="column in columns" :key="column.key">
                            <KanbanColumn
                                :column="column"
                                :tasks="getTasks(row.key, column.key)"
                                :row-label="row.label"
                                @drag="(evt) => onDrag(evt, column)"
                                @open="onOpen"
                                @create="onCreate(row.key)"
                                @update-attribute="onUpdateAttribute"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

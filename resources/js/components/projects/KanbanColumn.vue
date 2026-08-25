<script setup lang="ts">
import draggable from 'vuedraggable';
import { Plus } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import KanbanCard from './KanbanCard.vue';
import { KANBAN_UI } from '@/lib/kanban-theme';
import type { AssigneeOption } from '@/lib/assignees';
import { useKanbanPermissions } from '@/composables/kanban/useKanbanPermissions';
import { computed, onMounted, ref, watch } from 'vue';

const { isCreator } = useKanbanPermissions();

// projectsById/assigneeOptionsByProjectId default to empty here rather than being required —
// KanbanRowCollapsible.vue (the Dashboard2 demo's row) doesn't have this data threaded down to
// it, since that whole path is currently dead code (unreached by any live page, per its own
// comments); defaulting keeps it type-checking without wiring up an abandoned demo.
const props = withDefaults(
    defineProps<{
        column: KanbanColumnDef;
        // Every task for this row+status, unfiltered — search/priority/tag filtering is
        // applied per-card via matchesFilters() below (v-show), not by excluding tasks from
        // this list, so filtering never mounts/unmounts cards.
        tasks: ProjectDocument[];
        rowLabel: string;
        projectsById?: Map<string, Project>;
        assigneeOptionsByProjectId?: Map<string, AssigneeOption[]>;
        currentProject?: Project | null;
        matchesFilters?: (doc: ProjectDocument) => boolean;
    }>(),
    {
        projectsById: () => new Map(),
        assigneeOptionsByProjectId: () => new Map(),
        matchesFilters: () => true,
    },
);

const emit = defineEmits(['drag', 'open', 'create', 'update-attribute', 'update-tags']);

// Every card mounts several child components (two Selects, a Popover), and mounting all of
// them for every task at once is the real cost of a big initial page load — v-show (see
// below) keeps that cost from repeating on every filter change, but doesn't reduce the first
// mount. So the first batch renders immediately (covers a typical viewport) and the rest
// mounts progressively across animation frames instead of blocking one synchronous render.
// Only covers the column's *initial* size — a task arriving afterwards (drag, creation,
// status change) always reveals immediately, never waits on this loop.
const INITIAL_BATCH = 15;
const BATCH_SIZE = 15;
const initialLength = props.tasks.length;
const revealedCount = ref(Math.min(INITIAL_BATCH, initialLength));

const revealMore = () => {
    if (revealedCount.value >= initialLength) return;
    revealedCount.value = Math.min(revealedCount.value + BATCH_SIZE, initialLength);
    if (revealedCount.value < initialLength) {
        requestAnimationFrame(revealMore);
    }
};

onMounted(() => {
    if (revealedCount.value < initialLength) {
        requestAnimationFrame(revealMore);
    }
});

watch(
    () => props.tasks.length,
    (newLength) => {
        if (newLength > revealedCount.value) {
            revealedCount.value = newLength;
        }
    },
);

const revealedTasks = computed(() => props.tasks.slice(0, revealedCount.value));
</script>

<template>
    <div :class="KANBAN_UI.columnWrapper">
        <draggable
            :model-value="revealedTasks"
            :group="{ name: `tasks-${rowLabel}` }"
            item-key="id"
            class="flex-1 space-y-4 min-h-[100px]"
            :ghost-class="KANBAN_UI.ghostCard"
            @change="emit('drag', $event)"

            :component-data="{
                tag: 'div',
                type: 'transition-group',
                name: 'kanban-list'
            }"
        >
            <template #item="{ element: doc }">
                <div class="kanban-item" v-show="matchesFilters(doc)">
                    <KanbanCard
                        :doc="doc"
                        :column="column"
                        :document-project="projectsById.get(doc.project_id) ?? currentProject ?? null"
                        :assignee-options="assigneeOptionsByProjectId.get(doc.project_id) ?? []"
                        @open="emit('open', doc)"
                        @update-attribute="(field, val) => emit('update-attribute', doc.id, field, val)"
                        @update-tags="(categories) => emit('update-tags', doc.id, categories)"
                    />
                </div>
            </template>
        </draggable>

        <Button
            v-if="isCreator"
            variant="ghost"
            @click="emit('create')"
            :class="[
                'w-full h-14 rounded-2xl transition-all bg-transparent shadow-none mt-auto hover:text-projector-primary-600 hover:bg-white',
                KANBAN_UI.subtleLabel
            ]"
        >
            <Plus class="w-4 h-4 mr-2" /> New {{ rowLabel }}
        </Button>
    </div>
</template>

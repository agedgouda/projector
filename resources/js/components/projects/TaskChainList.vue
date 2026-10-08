<script setup lang="ts">
import TaskRowContent from '@/components/documents/TaskRowContent.vue';
import type { AssigneeOption } from '@/lib/assignees';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';
import { followersByPredecessor, orderAsChains } from '@/lib/taskChains';
import { ChevronRight, GripVertical, Search } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';
import draggable from 'vuedraggable';

// The Tasks tab's List mode (orgs that track task start dates only): the project's tasks as
// chains — each task nested under the one it waits on (one predecessor each, so it's a tree).
// Links are made by dragging a row onto another (or onto the "No dependency" strip to unlink),
// or in the task sheet (Waits On / Followed By); dates down a chain are kept in step by the
// server (TaskChainScheduler). Start dates pair with the internal due date, so that's the
// Due shown here even when the org also tracks external due dates.
const props = defineProps<{
    // Every task on this project's board (unfiltered — the tree's shape needs the whole set);
    // `matchesFilters` decides which rows show.
    tasks: ProjectDocument[];
    matchesFilters: (doc: ProjectDocument) => boolean;
    columns: KanbanColumnDef[];
    assigneeOptions: AssigneeOption[];
}>();

const emit = defineEmits<{
    (
        e: 'update',
        docId: string | number,
        field: string,
        value: any,
        message?: string,
    ): void;
    (e: 'open', doc: ProjectDocument): void;
}>();

const collapsedIds = ref(new Set<string>());
const toggleCollapsed = (id: string) => {
    const next = new Set(collapsedIds.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    collapsedIds.value = next;
};

const followersOf = computed(() => followersByPredecessor(props.tasks));

// Filtered rows keep their place in the tree; a row whose predecessor is filtered out moves
// up to the top level rather than disappearing with it.
const rows = computed(() =>
    orderAsChains(
        props.tasks.filter((task) => props.matchesFilters(task)),
        collapsedIds.value,
    ).map(({ item, depth, hasFollowers }) => ({
        doc: item,
        depth,
        hasFollowers,
    })),
);

const rowKey = (row: { doc: ProjectDocument }) => String(row.doc.id);

// --- Drag and drop: re-parent a row ---
// Only *which row* a task is dropped onto matters — sibling order isn't stored (siblings all
// start the same day) — so vuedraggable is used just for the gesture (mouse and touch alike) and
// `move` always cancels the reorder itself. The drop target is whatever is under the pointer —
// a row, or the "No dependency" strip, which SortableJS wouldn't report since it isn't a list
// item — tracked from the pointer's position, which native drags, mouse and touch all report.
const ROOT_TARGET = 'root';
const draggingId = ref<string | null>(null);
const dropTargetId = ref<string | null>(null);
// The dragged task and everything downstream of it — dropping onto any of those would loop.
const blockedIds = ref(new Set<string>());

const descendantIds = (id: string): Set<string> => {
    const found = new Set<string>();
    const queue = [id];
    while (queue.length) {
        const current = queue.shift()!;
        for (const follower of followersOf.value.get(current) ?? []) {
            const followerId = String(follower.id);
            if (!found.has(followerId)) {
                found.add(followerId);
                queue.push(followerId);
            }
        }
    }
    return found;
};

const targetAt = (x: number, y: number) => {
    const element = document
        .elementFromPoint(x, y)
        ?.closest<HTMLElement>('[data-drop-root], [data-task-id]');
    if (!element) {
        dropTargetId.value = null;
    } else if (element.dataset.dropRoot !== undefined) {
        dropTargetId.value = ROOT_TARGET;
    } else {
        const id = element.dataset.taskId;
        dropTargetId.value = id && !blockedIds.value.has(id) ? id : null;
    }
};

const trackPointer = (event: Event) => {
    const point =
        'touches' in event
            ? (event as TouchEvent).touches[0]
            : (event as MouseEvent);
    if (point) targetAt(point.clientX, point.clientY);
};
const POINTER_EVENTS = ['dragover', 'mousemove', 'touchmove'] as const;
// Capture phase: SortableJS stops dragover from bubbling past the list (its `dragoverBubble`
// option is off), so a bubbling document listener would never see a real drag move.
const LISTENER_OPTIONS = { capture: true, passive: true } as const;

const onDragStart = (event: { item: HTMLElement }) => {
    const id = event.item.dataset.taskId ?? null;
    draggingId.value = id;
    dropTargetId.value = null;
    blockedIds.value = id ? new Set([id, ...descendantIds(id)]) : new Set();
    POINTER_EVENTS.forEach((name) =>
        document.addEventListener(name, trackPointer, LISTENER_OPTIONS),
    );
};

const onDragMove = () => false;

onBeforeUnmount(() =>
    POINTER_EVENTS.forEach((name) =>
        document.removeEventListener(name, trackPointer, LISTENER_OPTIONS),
    ),
);

const shortDate = (value: string | null | undefined) => {
    if (!value) return null;
    const [, month, day] = value.slice(0, 10).split('-');
    return `${Number(month)}/${Number(day)}`;
};

const onDragEnd = () => {
    POINTER_EVENTS.forEach((name) =>
        document.removeEventListener(name, trackPointer, LISTENER_OPTIONS),
    );
    const id = draggingId.value;
    const target = dropTargetId.value;
    draggingId.value = null;
    dropTargetId.value = null;
    blockedIds.value = new Set();

    const task = props.tasks.find((t) => String(t.id) === id);
    if (!task || !target) return;

    const newPredecessorId = target === ROOT_TARGET ? null : target;
    if ((task.predecessor_id ?? null) === newPredecessorId) return;

    if (newPredecessorId === null) {
        emit(
            'update',
            task.id,
            'predecessor_id',
            null,
            `"${task.name}" no longer waits on another task`,
        );
        return;
    }

    const predecessor = props.tasks.find(
        (t) => String(t.id) === newPredecessorId,
    );
    const starts = shortDate(predecessor?.due_at);
    emit(
        'update',
        task.id,
        'predecessor_id',
        newPredecessorId,
        `"${task.name}" now waits on "${predecessor?.name}"${starts ? ` — starts ${starts}` : ''}`,
    );

    // Show where it landed.
    if (collapsedIds.value.has(newPredecessorId)) {
        toggleCollapsed(newPredecessorId);
    }
};
</script>

<template>
    <div>
        <!-- Same dense, striped rows as a meeting note's "Generated Tasks" list
             (DocumentContent.vue) so a long project stays scannable. -->
        <div
            class="hidden items-center gap-2.5 px-2 pb-2 text-[10px] font-black tracking-[0.2em] text-slate-400 uppercase md:flex"
        >
            <span class="flex-1">Task</span>
            <div class="flex shrink-0 items-center gap-5">
                <span class="w-28 text-right">Status</span>
                <span class="w-28">Start</span>
                <span class="w-28">Due</span>
            </div>
        </div>

        <draggable
            :list="rows"
            :item-key="rowKey"
            handle=".task-drag-handle"
            :move="onDragMove"
            :animation="0"
            ghost-class="opacity-40"
            @start="onDragStart"
            @end="onDragEnd"
        >
            <template #header>
                <div
                    v-show="draggingId"
                    data-drop-root
                    :class="[
                        'mb-1 flex h-9 items-center justify-center rounded-md border border-dashed text-[10px] font-black tracking-widest uppercase transition-colors',
                        dropTargetId === ROOT_TARGET
                            ? 'border-projector-primary-400 bg-projector-primary-50 text-projector-primary-600 dark:bg-projector-primary-950/40'
                            : 'border-gray-300 text-gray-400 dark:border-gray-700',
                    ]"
                >
                    No dependency — drop here to stand on its own
                </div>
            </template>

            <template #item="{ element: row, index }">
                <div
                    :data-task-id="String(row.doc.id)"
                    :class="[
                        'group relative flex min-h-9 cursor-pointer items-center gap-2.5 rounded-md px-2 transition-colors',
                        FLAT_ROW_HOVER,
                        index % 2 === 1
                            ? 'bg-projector-primary-100/70 dark:bg-projector-primary-950/25'
                            : '',
                        dropTargetId === String(row.doc.id) &&
                            'ring-2 ring-projector-primary-400 ring-inset',
                        draggingId &&
                            blockedIds.has(String(row.doc.id)) &&
                            String(row.doc.id) !== draggingId &&
                            'cursor-not-allowed opacity-40',
                    ]"
                    @click="emit('open', row.doc)"
                >
                    <span
                        class="task-drag-handle -ml-1 flex h-5 w-3.5 shrink-0 cursor-grab items-center justify-center text-slate-500 opacity-0 group-hover:opacity-100 hover:text-slate-800 active:cursor-grabbing active:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100 dark:active:text-white"
                        title="Drag onto another task to make this one wait on it"
                        @click.stop
                    >
                        <GripVertical class="h-3.5 w-3.5" />
                    </span>

                    <!-- Indent and collapse arrow as one element (an empty spacer would still cost a
                     row gap), 16px per level, pulled in toward the row so deep chains stay readable. -->
                    <div
                        class="-mr-2 flex shrink-0 items-center"
                        :style="{ paddingLeft: `${row.depth * 16}px` }"
                    >
                        <button
                            v-if="row.hasFollowers"
                            type="button"
                            class="flex h-5 w-4 items-center justify-center rounded text-slate-400 hover:bg-slate-200/60 hover:text-slate-600 dark:hover:bg-white/10"
                            :title="
                                collapsedIds.has(String(row.doc.id))
                                    ? 'Show the tasks that follow'
                                    : 'Hide the tasks that follow'
                            "
                            @click.stop="toggleCollapsed(String(row.doc.id))"
                        >
                            <ChevronRight
                                :class="[
                                    'h-3.5 w-3.5 transition-transform',
                                    !collapsedIds.has(String(row.doc.id)) &&
                                        'rotate-90',
                                ]"
                            />
                        </button>
                        <span v-else class="h-5 w-4"></span>
                    </div>

                    <TaskRowContent
                        :doc="row.doc"
                        :columns="columns"
                        :assignee-options="assigneeOptions"
                        :uses-external-due-dates="false"
                        :uses-task-start-dates="true"
                        only-live-processing
                        @update="
                            (field, value) =>
                                emit('update', row.doc.id, field, value)
                        "
                    />
                </div>
            </template>
        </draggable>

        <div
            v-if="!rows.length"
            class="flex flex-col items-center justify-center rounded-[2rem] border border-dashed border-gray-200 bg-gray-50/50 py-20 dark:border-gray-800 dark:bg-white/5"
        >
            <div
                class="mb-4 rounded-2xl bg-white p-4 shadow-sm dark:bg-white/10"
            >
                <Search class="h-8 w-8 text-gray-300" />
            </div>
            <p class="font-bold text-gray-900 dark:text-gray-100">
                No tasks to show
            </p>
            <p class="text-sm text-gray-500">
                Try adjusting your search or filters.
            </p>
        </div>
    </div>
</template>

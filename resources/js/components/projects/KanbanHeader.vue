<script setup lang="ts">
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { kanbanDotClasses } from '@/lib/constants';
import { KANBAN_UI } from '@/lib/kanban-theme';
import projectRoutes from '@/routes/projects';
import { router } from '@inertiajs/vue3';
import { MoreVertical, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

const props = defineProps<{
    columns: KanbanColumnDef[];
    getCount: (status: TaskStatus) => number;
    currentProject?: Project | null;
    canManage?: boolean;
}>();

const renamingId = ref<number | null>(null);
const renameLabel = ref('');
const isAdding = ref(false);
const newLabel = ref('');

const startRename = (column: KanbanColumnDef) => {
    renamingId.value = column.id;
    renameLabel.value = column.label;
};

const cancelRename = () => {
    renamingId.value = null;
};

const submitRename = (column: KanbanColumnDef) => {
    const label = renameLabel.value.trim();
    if (!label || !props.currentProject) {
        renamingId.value = null;
        return;
    }

    router.patch(
        projectRoutes.kanbanColumns.update.url({
            project: props.currentProject.id,
            kanbanColumn: column.id,
        }),
        { label },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                renamingId.value = null;
            },
            onError: () => toast.error('Failed to rename column.'),
        },
    );
};

const submitAdd = () => {
    const label = newLabel.value.trim();
    if (!label || !props.currentProject) {
        isAdding.value = false;
        return;
    }

    router.post(
        projectRoutes.kanbanColumns.store.url({
            project: props.currentProject.id,
        }),
        { label },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                isAdding.value = false;
                newLabel.value = '';
            },
            onError: () => toast.error('Failed to add column.'),
        },
    );
};

const pendingDeleteColumn = ref<KanbanColumnDef | null>(null);
const isDeleting = ref(false);

const confirmDeleteColumn = (column: KanbanColumnDef) => {
    pendingDeleteColumn.value = column;
};

const cancelDeleteColumn = () => {
    pendingDeleteColumn.value = null;
};

const executeDeleteColumn = () => {
    if (!props.currentProject || !pendingDeleteColumn.value) return;
    const column = pendingDeleteColumn.value;

    isDeleting.value = true;

    router.delete(
        projectRoutes.kanbanColumns.destroy.url({
            project: props.currentProject.id,
            kanbanColumn: column.id,
        }),
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                pendingDeleteColumn.value = null;
            },
            onError: (errors) =>
                toast.error(errors.kanban_column ?? 'Failed to delete column.'),
            onFinish: () => {
                isDeleting.value = false;
            },
        },
    );
};
</script>

<template>
    <div
        class="relative sticky top-0 z-20 w-full bg-light-background/90 px-4 backdrop-blur-md"
    >
        <div class="w-full" :style="KANBAN_UI.gridContainer(columns.length)">
            <div
                v-for="column in columns"
                :key="column.key"
                :class="[KANBAN_UI.columnHeader, 'group/header']"
            >
                <div
                    :class="[
                        'h-2 w-2 shrink-0 rounded-full shadow-sm',
                        kanbanDotClasses[column.color ?? 'slate'],
                    ]"
                ></div>

                <template v-if="renamingId === column.id">
                    <Input
                        v-model="renameLabel"
                        autofocus
                        class="h-6 w-32 text-[11px] font-black tracking-[0.15em] uppercase"
                        @keydown.enter="submitRename(column)"
                        @keydown.escape="cancelRename"
                        @blur="submitRename(column)"
                    />
                </template>
                <template v-else>
                    <div class="flex items-center gap-2">
                        <span :class="KANBAN_UI.headerTitle">
                            {{ column.label }}
                        </span>

                        <span
                            class="flex min-w-[20px] items-center justify-center rounded-full border border-gray-200/50 bg-gray-100 px-1.5 py-0.5 text-[9px] font-black text-gray-500"
                        >
                            {{ getCount(column.key) }}
                        </span>
                    </div>

                    <DropdownMenu v-if="canManage && currentProject">
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="h-5 w-5 opacity-0 transition-opacity group-hover/header:opacity-100"
                            >
                                <MoreVertical class="h-3 w-3 text-gray-400" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" class="w-36">
                            <DropdownMenuItem @click="startRename(column)">
                                <Pencil class="mr-2 h-3.5 w-3.5" />
                                Rename
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                @click="confirmDeleteColumn(column)"
                                class="text-red-600 focus:text-red-600"
                            >
                                <Trash2 class="mr-2 h-3.5 w-3.5" />
                                Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </div>
        </div>

        <Popover v-if="canManage && currentProject" v-model:open="isAdding">
            <PopoverTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon"
                    class="absolute top-1/2 right-2 h-7 w-7 -translate-y-1/2 rounded-full text-gray-400 hover:text-projector-primary-600"
                >
                    <Plus class="h-4 w-4" />
                </Button>
            </PopoverTrigger>
            <PopoverContent class="w-56 p-2" align="end">
                <form
                    class="flex items-center gap-2"
                    @submit.prevent="submitAdd"
                >
                    <Input
                        v-model="newLabel"
                        placeholder="Column name"
                        autofocus
                        class="h-8 text-xs"
                    />
                    <Button type="submit" size="sm" class="h-8 px-3"
                        >Add</Button
                    >
                </form>
            </PopoverContent>
        </Popover>

        <ConfirmDeleteModal
            :open="!!pendingDeleteColumn"
            :title="`Delete '${pendingDeleteColumn?.label}' Column`"
            description="This can't be undone. Move or clear any tasks in this column first, or the deletion will be rejected."
            :loading="isDeleting"
            @close="cancelDeleteColumn"
            @confirm="executeDeleteColumn"
        />
    </div>
</template>

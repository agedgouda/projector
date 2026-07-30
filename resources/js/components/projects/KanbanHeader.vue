<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Plus, MoreVertical, Pencil, Trash2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { kanbanDotClasses } from '@/lib/constants';
import { KANBAN_UI } from '@/lib/kanban-theme';
import projectRoutes from '@/routes/projects';

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
        projectRoutes.kanbanColumns.update.url({ project: props.currentProject.id, kanbanColumn: column.id }),
        { label },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { renamingId.value = null; },
            onError: () => toast.error('Failed to rename column.'),
        }
    );
};

const submitAdd = () => {
    const label = newLabel.value.trim();
    if (!label || !props.currentProject) {
        isAdding.value = false;
        return;
    }

    router.post(
        projectRoutes.kanbanColumns.store.url({ project: props.currentProject.id }),
        { label },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                isAdding.value = false;
                newLabel.value = '';
            },
            onError: () => toast.error('Failed to add column.'),
        }
    );
};

const deleteColumn = (column: KanbanColumnDef) => {
    if (!props.currentProject) return;
    if (!confirm(`Delete the "${column.label}" column?`)) return;

    router.delete(
        projectRoutes.kanbanColumns.destroy.url({ project: props.currentProject.id, kanbanColumn: column.id }),
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => toast.error(errors.kanban_column ?? 'Failed to delete column.'),
        }
    );
};
</script>

<template>
    <div class="sticky top-0 bg-light-background/90 backdrop-blur-md z-20 px-4 w-full relative">
        <div class="w-full" :style="KANBAN_UI.gridContainer(columns.length)">
            <div
                v-for="column in columns"
                :key="column.key"
                :class="[KANBAN_UI.columnHeader, 'group/header']"
            >
                <div :class="['h-2 w-2 rounded-full shadow-sm shrink-0', kanbanDotClasses[column.color ?? 'slate']]"></div>

                <template v-if="renamingId === column.id">
                    <Input
                        v-model="renameLabel"
                        autofocus
                        class="h-6 w-32 text-[11px] font-black uppercase tracking-[0.15em]"
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

                        <span class="flex items-center justify-center bg-gray-100 text-gray-500 text-[9px] font-black px-1.5 py-0.5 rounded-full min-w-[20px] border border-gray-200/50">
                            {{ getCount(column.key) }}
                        </span>
                    </div>

                    <DropdownMenu v-if="canManage && currentProject">
                        <DropdownMenuTrigger as-child>
                            <Button variant="ghost" size="icon" class="h-5 w-5 opacity-0 group-hover/header:opacity-100 transition-opacity">
                                <MoreVertical class="w-3 h-3 text-gray-400" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" class="w-36">
                            <DropdownMenuItem @click="startRename(column)">
                                <Pencil class="w-3.5 h-3.5 mr-2" />
                                Rename
                            </DropdownMenuItem>
                            <DropdownMenuItem @click="deleteColumn(column)" class="text-red-600 focus:text-red-600">
                                <Trash2 class="w-3.5 h-3.5 mr-2" />
                                Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </div>
        </div>

        <Popover v-if="canManage && currentProject" v-model:open="isAdding">
            <PopoverTrigger as-child>
                <Button variant="ghost" size="icon" class="absolute right-2 top-1/2 -translate-y-1/2 h-7 w-7 rounded-full text-gray-400 hover:text-projector-primary-600">
                    <Plus class="w-4 h-4" />
                </Button>
            </PopoverTrigger>
            <PopoverContent class="w-56 p-2" align="end">
                <form class="flex items-center gap-2" @submit.prevent="submitAdd">
                    <Input v-model="newLabel" placeholder="Column name" autofocus class="h-8 text-xs" />
                    <Button type="submit" size="sm" class="h-8 px-3">Add</Button>
                </form>
            </PopoverContent>
        </Popover>
    </div>
</template>

<script setup lang="ts">
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    ALL_PRIORITIES,
    SORT_OPTIONS,
    type Priority,
    type SortOption,
} from '@/composables/kanban/useKanbanQueries';
import { FLAT_SEARCH_ICON, FLAT_SEARCH_INPUT } from '@/lib/flat-ui';
import { getPriorityStyles } from '@/lib/kanban-theme';
import { kanbanDotClasses } from '@/lib/constants';
import { ListFilter, Search } from 'lucide-vue-next';
import KanbanRow from './KanbanRow.vue';

defineProps<{
    currentProject?: Project | null;
    hasRows: boolean;
    workflowRows: (DocumentSchemaItem & { columns: KanbanColumnDef[] })[];
    getTasksByRowAndStatus: (
        rowKey: string,
        status: TaskStatus,
    ) => ProjectDocument[];
    onDragChange: (event: any, column: KanbanColumnDef, rowKey: string) => void;
    openDetail: (doc: ProjectDocument) => void;
    handleCreateNew: (rowKey: string) => void;
    updateAttribute: (docId: string | number, field: string, value: string | number | null) => void;
    canViewProjectDetails?: boolean;
    canManageColumns?: boolean;
    availableTags?: CategoryDef[];
}>();

const searchQuery = defineModel<string>('searchQuery', { default: '' });
const selectedPriorities = defineModel<Priority[]>('selectedPriorities', {
    default: () => [...ALL_PRIORITIES],
});
const sortBy = defineModel<SortOption>('sortBy', { default: 'due_date' });
const excludedTagIds = defineModel<string[]>('excludedTagIds', {
    default: () => [],
});

const togglePriority = (priority: Priority) => {
    const current = selectedPriorities.value;
    const idx = current.indexOf(priority);
    if (idx === -1) {
        selectedPriorities.value = [...current, priority];
    } else {
        selectedPriorities.value = current.filter((p) => p !== priority);
    }
};

const toggleTag = (tagId: string) => {
    const current = excludedTagIds.value;
    const idx = current.indexOf(tagId);
    if (idx === -1) {
        excludedTagIds.value = [...current, tagId];
    } else {
        excludedTagIds.value = current.filter((id) => id !== tagId);
    }
};
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-center gap-3">
            <div class="group relative w-full md:w-80 lg:w-96">
                <Search :class="FLAT_SEARCH_ICON" />
                <Input
                    v-model="searchQuery"
                    placeholder="Search tasks or people... (Esc)"
                    :class="FLAT_SEARCH_INPUT"
                />
            </div>

            <div class="ml-auto flex items-center gap-2">
                <ListFilter class="h-3.5 w-3.5 text-gray-400" />
                <span
                    class="text-[10px] font-black tracking-widest text-gray-400 uppercase"
                    >Priority:</span
                >
                <button
                    v-for="priority in ALL_PRIORITIES"
                    :key="priority"
                    type="button"
                    @click="togglePriority(priority)"
                    :disabled="
                        selectedPriorities.includes(priority) &&
                        selectedPriorities.length === 1
                    "
                    :class="[
                        'flex items-center gap-1.5 rounded border px-2.5 py-1 text-[9px] font-black tracking-tighter uppercase transition-all',
                        selectedPriorities.includes(priority)
                            ? getPriorityStyles(priority)
                            : 'border-gray-200 bg-white text-gray-300 line-through',
                    ]"
                >
                    {{ priority }}
                </button>
            </div>

            <div
                v-if="availableTags?.length"
                class="flex items-center gap-2"
            >
                <span
                    class="text-[10px] font-black tracking-widest text-gray-400 uppercase"
                    >Tag:</span
                >
                <button
                    type="button"
                    @click="excludedTagIds = []"
                    class="rounded border border-gray-200 bg-white px-2 py-1 text-[9px] font-black tracking-tighter text-gray-500 uppercase transition-all hover:border-gray-300"
                >
                    All
                </button>
                <button
                    type="button"
                    @click="excludedTagIds = availableTags.map((t) => t.id)"
                    class="rounded border border-gray-200 bg-white px-2 py-1 text-[9px] font-black tracking-tighter text-gray-500 uppercase transition-all hover:border-gray-300"
                >
                    None
                </button>
                <button
                    v-for="tag in availableTags"
                    :key="tag.id"
                    type="button"
                    @click="toggleTag(tag.id)"
                    :class="[
                        'flex items-center gap-1.5 rounded border px-2.5 py-1 text-[9px] font-black tracking-tighter uppercase transition-all',
                        !excludedTagIds.includes(tag.id)
                            ? 'border-gray-200 bg-white text-gray-700'
                            : 'border-gray-200 bg-white text-gray-300 line-through',
                    ]"
                >
                    <span
                        :class="[
                            'h-1.5 w-1.5 shrink-0 rounded-full',
                            kanbanDotClasses[tag.color],
                        ]"
                    ></span>
                    {{ tag.name }}
                </button>
            </div>

            <Select v-model="sortBy">
                <SelectTrigger
                    class="h-8 w-full text-[10px] font-black tracking-widest uppercase md:w-[150px]"
                >
                    <SelectValue placeholder="Sort By" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in SORT_OPTIONS"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div v-if="hasRows" class="block w-full min-w-0">
            <div class="block w-full space-y-8">
                <KanbanRow
                    v-for="row in workflowRows"
                    :key="row.key"
                    :row="row"
                    :columns="row.columns"
                    :get-tasks="
                        (rowKey, status) =>
                            getTasksByRowAndStatus(rowKey, status)
                    "
                    :on-drag="
                        (evt, column) => onDragChange(evt, column, row.key)
                    "
                    :on-open="openDetail"
                    :on-create="(key) => handleCreateNew(key)"
                    :on-update-attribute="updateAttribute"
                    :can-view-project-details="canViewProjectDetails"
                    :current-project="currentProject"
                    :can-manage="canManageColumns"
                />
            </div>
        </div>

        <div
            v-else
            class="flex flex-col items-center justify-center rounded-[2rem] border border-dashed border-gray-200 bg-gray-50/50 py-20"
        >
            <div class="mb-4 rounded-2xl bg-white p-4 shadow-sm">
                <Search class="h-8 w-8 text-gray-300" />
            </div>
            <p class="font-bold text-gray-900">No projects to show</p>
            <p class="text-sm text-gray-500">
                Try adjusting your search or filters.
            </p>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import { Search, Filter, ArrowUpDown, User2 } from 'lucide-vue-next'; // Added User2
import TaskCard from '@/components/tasks/TaskCard.vue';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

const props = defineProps<{
    tasks: Task[];
    users: User[];
}>();

// --- STATE ---
const searchQuery = ref('');
const statusFilter = ref('all');
const assigneeFilter = ref('all'); // Added
const sortBy = ref('priority');

// --- LOGIC ---
const filteredTasks = computed(() => {
    let result = [...props.tasks];

    // 1. Search Filter
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        result = result.filter(t =>
            t.title?.toLowerCase().includes(query) ||
            t.description?.toLowerCase().includes(query)
        );
    }

    // 2. Status Filter
    if (statusFilter.value !== 'all') {
        result = result.filter(t => t.status === statusFilter.value);
    }

    // 3. Assignee Filter (Added)
    if (assigneeFilter.value !== 'all') {
        if (assigneeFilter.value === 'unassigned') {
            result = result.filter(t => !t.assignee_id);
        } else {
            result = result.filter(t => t.assignee_id?.toString() === assigneeFilter.value);
        }
    }

    // 4. Sorting
    result.sort((a, b) => {
        if (sortBy.value === 'priority') {
            const weights: Record<string, number> = { urgent: 4, high: 3, medium: 2, low: 1 };
            return (weights[b.priority] || 0) - (weights[a.priority] || 0);
        }
        if (sortBy.value === 'newest') {
            return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
        }
        if (sortBy.value === 'due_date') {
            return new Date(a.due_at || '9999-12-31').getTime() - new Date(b.due_at || '9999-12-31').getTime();
        }
        // Added Assignee Sort (Alphabetical by First Name)
        if (sortBy.value === 'assignee') {
            const nameA = a.assignee?.first_name || 'ZZZ';
            const nameB = b.assignee?.first_name || 'ZZZ';
            return nameA.localeCompare(nameB);
        }
        return 0;
    });

    return result;
});

const resetFilters = () => {
    searchQuery.value = '';
    statusFilter.value = 'all';
    assigneeFilter.value = 'all';
};
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-white dark:bg-slate-900 p-2 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
            <div class="relative w-full md:w-80 lg:w-96">
                <Search class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                <Input
                    v-model="searchQuery"
                    placeholder="Search tasks..."
                    class="pl-11 bg-slate-50 dark:bg-slate-950 border-none h-11 rounded-xl focus-visible:ring-1 focus-visible:ring-slate-300"
                />
            </div>

            <div class="flex items-center gap-2 w-full md:w-auto pr-2">
                <Select v-model="assigneeFilter">
                    <SelectTrigger class="w-full md:w-[150px] bg-slate-50 dark:bg-slate-950 border-none h-11 rounded-xl">
                        <User2 class="w-3 h-3 mr-2 opacity-50" />
                        <SelectValue placeholder="Assignee" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Everyone</SelectItem>
                        <SelectItem value="unassigned">Unassigned</SelectItem>
                        <SelectItem v-for="user in users" :key="user.id" :value="user.id.toString()">
                            {{ user.first_name }} {{ user.last_name }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Select v-model="statusFilter">
                    <SelectTrigger class="w-full md:w-[140px] bg-slate-50 dark:bg-slate-950 border-none h-11 rounded-xl">
                        <Filter class="w-3 h-3 mr-2 opacity-50" />
                        <SelectValue placeholder="Status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Statuses</SelectItem>
                        <SelectItem value="todo">To Do</SelectItem>
                        <SelectItem value="in_progress">In Progress</SelectItem>
                        <SelectItem value="completed">Completed</SelectItem>
                    </SelectContent>
                </Select>

                <Select v-model="sortBy">
                    <SelectTrigger class="w-full md:w-[140px] bg-slate-50 dark:bg-slate-950 border-none h-11 rounded-xl">
                        <ArrowUpDown class="w-3 h-3 mr-2 opacity-50" />
                        <SelectValue placeholder="Sort By" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="priority">Priority</SelectItem>
                        <SelectItem value="newest">Newest First</SelectItem>
                        <SelectItem value="due_date">Due Date</SelectItem>
                        <SelectItem value="assignee">Assignee</SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </div>

        <div class="hidden md:grid grid-cols-[1fr_120px_100px_120px] gap-4 px-6 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">
            <span>Task Detail</span>
            <span class="text-right">Status</span>
            <span class="text-right">Priority</span>
            <span class="text-right">Due Date</span>
        </div>

        <div v-if="filteredTasks.length > 0" class="grid gap-3">
            <TaskCard
                v-for="task in filteredTasks"
                :key="task.id"
                :task="task"
                :users="users"
            />
        </div>

        <div v-else-if="tasks.length > 0" class="py-20 text-center bg-slate-50 dark:bg-slate-900/50 rounded-3xl border-2 border-dashed border-slate-200 dark:border-slate-800">
            <p class="text-slate-500 font-medium text-sm">No tasks match your current filters.</p>
            <button @click="resetFilters" class="mt-2 text-xs font-bold text-blue-600 uppercase tracking-widest hover:underline">
                Clear Filters
            </button>
        </div>

        <div v-else class="py-20 text-center bg-slate-50 dark:bg-slate-900/50 rounded-3xl border-2 border-dashed border-slate-200 dark:border-slate-800">
            <p class="text-slate-400 font-medium">No tasks created yet. Head to the Documentation tab to start building.</p>
        </div>
    </div>
</template>

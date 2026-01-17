<script setup lang="ts">
import { computed } from 'vue';
import { User2, HelpCircle } from 'lucide-vue-next';
// We'll reuse your existing TaskCard if you have one, or build a refined version
import TaskCard from '@/components/tasks/TaskCard.vue';

const props = defineProps<{
    tasks: Task[];
    users: User[];
}>();

// Grouping Logic: The heart of "Who is doing what"
const groupedTasks = computed(() => {
    const groups: Record<string, any> = {
        unassigned: {
            user: { name: 'Unassigned', id: 'unassigned' },
            tasks: []
        }
    };

    // Initialize groups for all project users
    props.users.forEach(user => {
        groups[user.id] = {
            user: user,
            tasks: []
        };
    });

    // Distribute tasks into buckets
    props.tasks.forEach(task => {
        const key = task.assignee_id || 'unassigned';
        if (groups[key]) {
            groups[key].tasks.push(task);
        } else {
            // Fallback for edge cases where assignee isn't in users prop
            groups.unassigned.tasks.push(task);
        }
    });

    // Only return groups that have tasks to keep the UI clean
    return Object.values(groups).filter(group => group.tasks.length > 0);
});
</script>

<template>
    <div class="space-y-10">
        <div v-for="group in groupedTasks" :key="group.user.id" class="space-y-4">

            <div class="flex items-center justify-between px-2">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center border border-slate-200">
                        <User2 v-if="group.user.id !== 'unassigned'" class="w-4 h-4 text-slate-500" />
                        <HelpCircle v-else class="w-4 h-4 text-orange-500" />
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">{{ group.user.name }}</h3>
                        <p class="text-[10px] uppercase tracking-wider text-slate-400 font-black">
                            {{ group.tasks.length }} {{ group.tasks.length === 1 ? 'Task' : 'Tasks' }}
                        </p>
                    </div>
                </div>

                <div class="hidden md:grid grid-cols-[120px_100px_120px] gap-4 text-right pr-4">
                    <span class="text-[10px] font-black uppercase tracking-widest text-slate-300">Status</span>
                    <span class="text-[10px] font-black uppercase tracking-widest text-slate-300">Priority</span>
                    <span class="text-[10px] font-black uppercase tracking-widest text-slate-300">Due Date</span>
                </div>
            </div>

            <div class="grid gap-3">
                <TaskCard
                    v-for="task in group.tasks"
                    :key="task.id"
                    :task="task"
                    :users="users"
                />
            </div>
        </div>

        <div v-if="tasks.length === 0" class="py-20 text-center bg-slate-50 rounded-3xl border-2 border-dashed border-slate-200">
            <p class="text-slate-400 font-medium">No tasks created yet. Head to the Documentation tab to start building.</p>
        </div>
    </div>
</template>

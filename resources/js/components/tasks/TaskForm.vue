<script setup lang="ts">
import { computed } from 'vue';
import type { InertiaForm } from '@inertiajs/vue3';
import { User2, Activity, Calendar as CalendarIcon } from 'lucide-vue-next';

// UI Components
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

const props = defineProps<{
    form: InertiaForm<Partial<Task>>;
    users: User[];
}>();


const updateField = <K extends keyof Task>(field: K, value: any) => {
    (props.form as any)[field] = value;
};

const handleAssigneeChange = (value: any) => {
    const stringValue = String(value);
    updateField('assignee_id', stringValue === 'unassigned' || !value ? null : parseInt(stringValue));
};

const handleDateChange = (e: Event) => {
    const val = (e.target as HTMLInputElement).value;
    updateField('due_at', val || null);
};

const dateProxy = computed({
    get() {
        if (!props.form.due_at) return '';
        // Slice to exactly YYYY-MM-DD
        return props.form.due_at.slice(0, 10);
    },
    set(val) {
        // When the user picks a date, update the form directly
        updateField('due_at', val || null);
    }
});
</script>

<template>
    <div class="space-y-6">
        <div class="grid gap-2">
            <Label class="text-[10px] font-black uppercase tracking-[0.15em] text-slate-400 ml-1">Task Title</Label>
            <Input
                :model-value="form.title ?? ''"
                @update:model-value="(v) => updateField('title', v)"
                placeholder="e.g. Implement user authentication logic"
                class="h-12 text-base border-slate-200 rounded-xl shadow-sm"
            />
            <p v-if="form.errors.title" class="text-xs text-red-500 ml-1">{{ form.errors.title }}</p>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div class="grid gap-2">
                <Label class="text-[10px] font-black uppercase tracking-[0.15em] text-slate-400 ml-1 flex items-center gap-1.5">
                    <User2 class="w-3 h-3" /> Assignee
                </Label>
                <Select
                    :model-value="form.assignee_id?.toString() ?? 'unassigned'"
                    @update:model-value="handleAssigneeChange"
                >
                    <SelectTrigger class="bg-white h-11 border-slate-200 rounded-xl shadow-sm">
                        <SelectValue placeholder="Select member" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="unassigned">Unassigned</SelectItem>
                        <SelectItem v-for="user in users" :key="user.id" :value="user.id.toString()">
                            {{ user.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="grid gap-2">
                <Label class="text-[10px] font-black uppercase tracking-[0.15em] text-slate-400 ml-1 flex items-center gap-1.5">
                    <Activity class="w-3 h-3" /> Status
                </Label>
                <Select
                    :model-value="form.status ?? 'todo'"
                    @update:model-value="(v) => updateField('status', v)"
                >
                    <SelectTrigger class="bg-white h-11 border-slate-200 rounded-xl">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="todo">To Do</SelectItem>
                        <SelectItem value="backlog">Backlog</SelectItem>
                        <SelectItem value="in_progress">In Progress</SelectItem>
                        <SelectItem value="done">Done</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="grid gap-2">
                <Label class="text-[10px] font-black uppercase tracking-[0.15em] text-slate-400 ml-1 flex items-center gap-1.5">
                    <CalendarIcon class="w-3 h-3" /> Due Date
                </Label>
                <Input
                    type="date"
                    v-model="dateProxy"
                    @input="handleDateChange"
                    class="h-11 bg-white border-slate-200 rounded-xl"
                />
            </div>
        </div>

        <div class="grid gap-2">
            <Label class="text-[10px] font-black uppercase tracking-[0.15em] text-slate-400 ml-1">Implementation Notes</Label>
            <textarea
                :value="form.description ?? ''"
                @input="(e: any) => updateField('description', e.target.value)"
                class="min-h-[140px] w-full rounded-2xl border border-slate-200 p-4 text-sm resize-none outline-none focus:ring-2 focus:ring-indigo-500 transition-all"
            ></textarea>
        </div>
    </div>
</template>

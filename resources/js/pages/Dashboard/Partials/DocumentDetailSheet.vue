<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle
} from '@/components/ui/sheet';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from '@/components/ui/select';
import {
    STATUS_LABELS,
    PRIORITY_LABELS,
    statusDotClasses,
    priorityDotClasses
} from '@/lib/constants';
import { Clock, User as UserIcon, Calendar, Activity, ShieldAlert } from 'lucide-vue-next';

const props = defineProps<{
    open: boolean;
    document: ProjectDocument;
}>();

const emit = defineEmits(['update:open', 'update-attribute']);
const page = usePage<AppPageProps>();

const currentProject = computed(() => (page.props as any).currentProject as Project | null);

const typeLabel = computed(() => {
    const schemaItem = currentProject.value?.type?.document_schema?.find(s => s.key === props.document.type);
    return schemaItem?.label || props.document.type;
});

const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
};

/**
 * Handle updates with type-casting.
 * We use 'any' for value here to accept the broad 'AcceptableValue' type
 * coming from the Select components.
 */
const handleUpdate = (field: string, value: any) => {
    let finalValue = value;

    if (field === 'assignee_id') {
        finalValue = (value === 'unassigned' || value === null) ? null : parseInt(value, 10);
    }

    // Ensure that if a date is cleared, we send null, otherwise send the string
    if (field === 'due_at') {
        finalValue = value === '' ? null : value;
    }

    emit('update-attribute', field, finalValue);
};
</script>

<template>
    <Sheet :open="open" @update:open="val => emit('update:open', val)">
        <SheetContent class="sm:max-w-[720px] overflow-y-auto border-l border-gray-100 shadow-2xl pl-12 pr-12 bg-white">
            <template v-if="document">
                <div class="mt-8 space-y-10">
                    <SheetHeader class="space-y-0.5 text-left p-0">
                        <div class="flex items-center mb-3">
                            <span class="text-[10px] font-black uppercase tracking-widest text-indigo-600 bg-indigo-50 px-2 py-1 rounded border border-indigo-100">
                                {{ typeLabel }}
                            </span>
                        </div>
                        <SheetTitle class="text-3xl font-bold text-gray-900 leading-tight">
                            {{ document.name }}
                        </SheetTitle>
                        <div class="flex items-center gap-1.5 text-[9px] font-black uppercase tracking-widest text-gray-400">
                            <Clock class="w-3 h-3" /> Updated {{ formatDate(document.updated_at) }}
                        </div>
                    </SheetHeader>

                    <section>
                        <div class="grid grid-cols-2 gap-x-12 gap-y-6">
                            <div class="flex justify-between items-center h-8 border-b border-gray-200/50 pb-2">
                                <span class="text-gray-500 text-[11px] font-medium flex items-center gap-2">
                                    <UserIcon class="w-3.5 h-3.5" /> Assignee
                                </span>
                                <Select
                                    v-if="currentProject?.client?.users"
                                    :model-value="document.assignee_id?.toString() ?? 'unassigned'"
                                    @update:model-value="val => handleUpdate('assignee_id', val)"
                                >
                                    <SelectTrigger class="h-auto p-0 border-none bg-transparent hover:bg-gray-200/50 px-2 py-1 rounded-md transition-all shadow-none w-auto outline-none">
                                        <span class="font-black uppercase tracking-wider text-gray-700 text-[10px]"><SelectValue /></span>
                                    </SelectTrigger>
                                    <SelectContent align="end" class="min-w-[160px]">
                                        <SelectItem value="unassigned" class="text-[10px] uppercase font-bold text-gray-400">Unassigned</SelectItem>
                                        <SelectItem v-for="user in currentProject.client.users" :key="user.id" :value="user.id.toString()" class="text-[10px] uppercase font-bold">
                                            {{ user.name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div class="flex justify-between items-center h-8 border-b border-gray-200/50 pb-2">
                                <span class="text-gray-500 text-[11px] font-medium flex items-center gap-2">
                                    <Calendar class="w-3.5 h-3.5" /> Due Date
                                </span>
                                <input
                                    type="date"
                                    :value="document.due_at ? document.due_at.slice(0, 10) : ''"
                                    @change="e => handleUpdate('due_at', (e.target as HTMLInputElement).value)"
                                    class="bg-transparent border-none p-0 text-[10px] font-black uppercase tracking-wider text-gray-700 focus:ring-0 cursor-pointer text-right w-[100px]"
                                />
                            </div>


                            <div class="flex justify-between items-center h-8 border-b border-gray-200/50 pb-2">
                                <span class="text-gray-500 text-[11px] font-medium flex items-center gap-2">
                                    <Activity class="w-3.5 h-3.5" /> Status
                                </span>
                                <Select
                                    :model-value="document.task_status ?? 'todo'"
                                    @update:model-value="val => handleUpdate('task_status', val)"
                                >
                                    <SelectTrigger class="h-auto p-0 border-none bg-transparent hover:bg-gray-200/50 px-2 py-1 rounded-md transition-all shadow-none w-auto outline-none">
                                        <div class="flex items-center gap-2">
                                            <span class="font-black uppercase tracking-wider text-gray-700 text-[10px]"><SelectValue /></span>
                                            <div :class="[statusDotClasses[document.task_status ?? 'todo'], 'w-2 h-2 rounded-full']"></div>
                                        </div>
                                    </SelectTrigger>
                                    <SelectContent align="end">
                                        <SelectItem v-for="(label, key) in STATUS_LABELS" :key="key" :value="key" class="text-[10px] font-black uppercase">
                                            <div class="flex items-center justify-between w-24">
                                                {{ label }}
                                                <div :class="[statusDotClasses[key], 'w-2 h-2 rounded-full']"></div>
                                            </div>
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div class="flex justify-between items-center h-8 border-b border-gray-200/50 pb-2">
                                <span class="text-gray-500 text-[11px] font-medium flex items-center gap-2">
                                    <ShieldAlert class="w-3.5 h-3.5" /> Priority
                                </span>
                                <Select
                                    :model-value="document.priority ?? 'low'"
                                    @update:model-value="val => handleUpdate('priority', val)"
                                >
                                    <SelectTrigger class="h-auto p-0 border-none bg-transparent hover:bg-gray-200/50 px-2 py-1 rounded-md transition-all shadow-none w-auto outline-none">
                                        <div class="flex items-center gap-2">
                                            <span class="font-black uppercase tracking-wider text-gray-700 text-[10px]"><SelectValue /></span>
                                            <div :class="[priorityDotClasses[document.priority ?? 'low'], 'w-2 h-2 rounded-full']"></div>
                                        </div>
                                    </SelectTrigger>
                                    <SelectContent align="end">
                                        <SelectItem v-for="(label, key) in PRIORITY_LABELS" :key="key" :value="key" class="text-[10px] font-black uppercase">
                                            <div class="flex items-center justify-between w-24">
                                                {{ label }}
                                                <div :class="[priorityDotClasses[key], 'w-2 h-2 rounded-full']"></div>
                                            </div>
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <h4 class="text-[11px] font-black uppercase tracking-widest text-gray-400 mt-10">Content</h4>
                        <div class="bg-white rounded-2xl p-0 text-base text-gray-600 leading-relaxed min-h-[300px] whitespace-pre-wrap mt-4">
                            {{ document.content || 'No content provided.' }}
                        </div>
                    </section>
                </div>
            </template>
        </SheetContent>
    </Sheet>
</template>

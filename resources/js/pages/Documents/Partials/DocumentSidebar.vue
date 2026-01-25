<script setup lang="ts">
import { RefreshCw } from 'lucide-vue-next';
import { formatDate } from '@/lib/utils';
import {
    STATUS_LABELS,
    PRIORITY_LABELS,
    statusDotClasses,
    priorityDotClasses
} from '@/lib/constants';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from '@/components/ui/select';
import { useDocumentPresenter } from '@/composables/useDocumentPresenter';

const props = defineProps<{
    project: Project;
    // Changed to 'any' or a partial to allow the Inertia Form in Create.vue
    // without missing-property errors.
    item: ExtendedDocument | any;
    dueAtProxy: string;
}>();

defineEmits<{
    (e: 'change', field: string, val: any): void;
    (e: 'update:dueAtProxy', val: string): void;
}>();

const { getDocLabel } = useDocumentPresenter(props.project);
</script>

<template>
    <aside class="col-span-12 lg:col-span-4">
        <div class="sticky top-10 space-y-6">
            <div class="bg-slate-50 rounded-3xl border border-slate-200 p-8 space-y-8">
                <div>
                    <h4 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-4">Properties</h4>

                    <div class="space-y-5">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-slate-500">Category</span>
                            <span class="font-black uppercase tracking-wider text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded text-[9px] border border-indigo-100">
                                {{ getDocLabel(item.type) || 'New Document' }}
                            </span>
                        </div>

                        <div class="flex flex-col">
                            <div class="flex justify-between items-center h-[24px]">
                                <span class="text-slate-500 text-xs">Assignee</span>
                                <Select :model-value="item.assignee_id?.toString() ?? 'unassigned'" @update:model-value="(val) => $emit('change', 'assignee_id', val)">
                                    <SelectTrigger class="h-auto p-0 border-none bg-transparent hover:bg-slate-100 rounded-md transition-all shadow-none w-auto outline-none">
                                        <div class="px-2 py-1">
                                            <span class="relative left-[10px] font-black uppercase tracking-[0.12em] text-slate-700 text-[10px]"><SelectValue /></span>
                                        </div>
                                    </SelectTrigger>
                                    <SelectContent align="end" class="min-w-[160px]">
                                        <SelectItem value="unassigned" class="text-[10px] uppercase font-bold text-slate-400">Unassigned</SelectItem>
                                        <SelectItem v-for="user in project.client.users" :key="user.id" :value="user.id.toString()" class="text-[10px] uppercase font-bold">{{ user.name }}</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div class="flex justify-between items-center h-[24px]">
                                <span class="text-slate-500 text-xs">Due Date</span>
                                <div class="flex items-center hover:bg-slate-100 pl-2 pr-1 rounded transition-colors cursor-pointer mr-[-3px]">
                                        <input
                                            type="date"
                                            :value="dueAtProxy"
                                            @input="$emit('update:dueAtProxy', ($event.target as HTMLInputElement).value)"
                                            :class="[
                                                'custom-date-input bg-transparent border-none p-0 text-[10px] font-black uppercase tracking-[0.12em] text-slate-700 focus:ring-0 cursor-pointer text-right',
                                                !dueAtProxy ? 'w-[109px] is-empty' : 'w-[93px]'
                                            ]"
                                        />
                                </div>
                            </div>

                            <div class="flex justify-between items-center h-[24px]">
                                <span class="text-slate-500 text-xs">Priority</span>
                                <Select :model-value="item.priority" @update:model-value="(val) => $emit('change', 'priority', val)">
                                    <SelectTrigger class="h-auto p-0 border-none bg-transparent hover:bg-slate-100 rounded-md transition-all shadow-none w-auto outline-none">
                                        <div class="px-2 py-1">
                                            <span class="relative left-[10px] font-black uppercase tracking-[0.12em] text-slate-700 text-[10px] flex items-center">
                                                <SelectValue />
                                                <div :class="[priorityDotClasses[item.priority ?? 'low'], 'w-2 h-2 rounded-full ml-2 flex-shrink-0']"></div>
                                            </span>
                                        </div>
                                    </SelectTrigger>
                                    <SelectContent align="end" class="min-w-[160px]">
                                        <SelectItem v-for="(label, key) in PRIORITY_LABELS" :key="key" :value="key" class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-700 cursor-pointer focus:bg-slate-50">
                                            <div class="flex items-center justify-between w-full">
                                                <span>{{ label }}</span>
                                                <div :class="[priorityDotClasses[key], 'w-2 h-2 rounded-full ml-4 flex-shrink-0']"></div>
                                            </div>
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div class="flex justify-between items-center h-[24px]">
                                <span class="text-slate-500 text-xs">Status</span>
                                <Select :model-value="item.task_status ?? 'todo'" @update:model-value="(val) => $emit('change', 'task_status', val)">
                                    <SelectTrigger class="h-auto p-0 border-none bg-transparent hover:bg-slate-100 rounded-md transition-all shadow-none w-auto outline-none">
                                        <div class="px-2 py-1">
                                            <span class="relative left-[10px] font-black uppercase tracking-[0.12em] text-slate-700 text-[10px] flex items-center">
                                                <SelectValue />
                                                <div :class="[statusDotClasses[item.task_status ?? 'todo'], 'w-2 h-2 rounded-full ml-2 flex-shrink-0']"></div>
                                            </span>
                                        </div>
                                    </SelectTrigger>
                                    <SelectContent align="end" class="min-w-[160px]">
                                        <SelectItem v-for="(label, key) in STATUS_LABELS" :key="key" :value="key" class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-700 cursor-pointer">
                                            <div class="flex items-center justify-between w-full min-w-[120px]">
                                                <span>{{ label }}</span>
                                                <div :class="[statusDotClasses[key], 'w-2 h-2 rounded-full ml-4 flex-shrink-0']"></div>
                                            </div>
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div v-if="item.id || item.processed_at === null" class="flex items-center justify-between text-xs pt-2">
                            <span class="text-slate-500">AI Status</span>
                            <div v-if="item.currentStatus || item.processed_at === null" class="flex items-center gap-1.5 text-indigo-600 animate-pulse">
                                <RefreshCw class="h-3 w-3 animate-spin" />
                                <span class="font-black uppercase text-[9px]">{{ item.currentStatus || 'Processing' }}</span>
                            </div>
                            <span v-else class="text-emerald-600 font-bold uppercase text-[9px]">Analyzed</span>
                        </div>
                    </div>
                </div>

                <div v-if="item.id" class="pt-6 border-t border-slate-200">
                    <h4 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-4">Dates</h4>
                    <div class="space-y-4">
                        <div class="flex items-center justify-between text-[10px] uppercase tracking-wider">
                            <span class="text-slate-500">Created</span>
                            <div class="flex items-center gap-1.5 font-bold">
                                <span class="text-slate-700">{{ formatDate(item.created_at) }}</span>
                                <span v-if="item.creator?.name" class="text-slate-400 font-medium lowercase italic">by</span>
                                <span v-if="item.creator?.name" class="text-indigo-600">{{ item.creator?.name }}</span>
                            </div>
                        </div>
                        <div class="flex items-center justify-between text-[10px] uppercase tracking-wider">
                            <span class="text-slate-500">Last Updated</span>
                            <div class="flex items-center gap-1.5 font-bold">
                                <span class="text-slate-700">{{ formatDate(item.updated_at) }}</span>
                                <span class="text-slate-400 font-medium lowercase italic">by</span>
                                <span v-if="item.editor?.name" class="text-indigo-600">{{ item.editor?.name }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </aside>
</template>

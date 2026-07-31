<script setup lang="ts">
import { formatDate } from '@/lib/utils';
import { computed } from 'vue';
import {
    PRIORITY_LABELS,
    kanbanDotClasses,
    priorityDotClasses
} from '@/lib/constants';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { Sparkles, RefreshCw, FileDown } from 'lucide-vue-next';
import { useDocumentPresenter } from '@/composables/useDocumentPresenter';
import { exportPdf } from '@/actions/App/Http/Controllers/DocumentController';

const props = defineProps<{
    project: Project;
    item: ExtendedDocument | any;
    documentTypeCatalog?: DocumentSchemaItem[];
    dueAtProxy: string;
    usesExternalDueDates?: boolean;
    needsReprocess?: boolean;
    isProcessingLive?: boolean;
    processingMessage?: string | null;
}>();

defineEmits<{
    (e: 'change', field: string, val: any): void;
    (e: 'update:dueAtProxy', val: string): void;
    (e: 'request-process'): void;
}>();

const { getDocLabel, isTask } = useDocumentPresenter(props.documentTypeCatalog);
const shouldShowTask = computed(() => isTask(props.item.type));

const assigneeValue = computed(() => {
    if (props.item.pending_assignee_invitation_id) {
        return `inv:${props.item.pending_assignee_invitation_id}`;
    }
    return props.item.assignee_id?.toString() ?? 'unassigned';
});

const invitations = computed(() => props.project.client.organization?.invitations ?? []);
const columns = computed(() => props.project.kanban_columns ?? []);

</script>

<template>
    <aside class="col-span-12 lg:col-span-4">
        <div class="sticky top-10 space-y-6">
            <div class="bg-slate-50 dark:bg-white/5 rounded-3xl border border-slate-200 dark:border-white/10 p-8 space-y-8">
                <div>
                    <h4 class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-700 dark:text-slate-500 mb-4">Properties</h4>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between text-[13px]">
                            <span class="text-slate-900">Category</span>
                            <span class="font-black uppercase tracking-wider text-projector-primary-600 dark:text-projector-primary-400 bg-projector-primary-50 dark:bg-projector-primary-950 px-2 py-0.5 rounded text-[11px] border border-projector-primary-100 dark:border-projector-primary-800">
                                {{ getDocLabel(item.type) || 'New Document' }}
                            </span>
                        </div>

                        <Button
                            v-if="needsReprocess && !isProcessingLive"
                            variant="outline"
                            size="sm"
                            class="w-full"
                            @click="$emit('request-process')"
                        >
                            <Sparkles class="h-3.5 w-3.5" />
                            Process Document
                        </Button>

                        <div
                            v-else-if="isProcessingLive"
                            class="flex items-center justify-center gap-1.5 w-full h-8 text-[13px] text-projector-primary-600 dark:text-projector-primary-400"
                        >
                            <RefreshCw class="w-3.5 h-3.5 animate-spin" />
                            <span class="animate-pulse">{{ processingMessage || 'Processing...' }}</span>
                        </div>

                        <div class="flex flex-col" v-if="shouldShowTask">
                            <div class="flex justify-between items-center min-h-[24px]">
                                <span class="text-slate-900 dark:text-slate-400 text-[13px]">Assignee</span>
                                <Select :model-value="assigneeValue" :disabled="project.inactive" @update:model-value="(val) => $emit('change', 'assignee_id', val)">
                                    <SelectTrigger class="h-auto p-0 border-none bg-transparent dark:!bg-transparent hover:bg-slate-100 dark:hover:bg-white/10 focus:bg-transparent dark:focus:bg-transparent focus-visible:ring-0 rounded-md transition-all shadow-none w-auto outline-none disabled:opacity-50 disabled:pointer-events-none">
                                        <div class="px-2 py-1">
                                            <span class="relative left-[10px] font-black uppercase tracking-[0.12em] text-slate-900 dark:text-slate-200 text-[13px]"><SelectValue /></span>
                                        </div>
                                    </SelectTrigger>
                                    <SelectContent align="end" class="min-w-[200px]">
                                        <SelectItem value="unassigned" class="text-[13px] uppercase font-bold text-slate-400">Unassigned</SelectItem>
                                        <SelectItem v-for="user in project.client.organization?.users" :key="user.id" :value="user.id.toString()" class="text-[13px] uppercase font-bold">{{ user.name }}</SelectItem>
                                        <template v-if="invitations.length > 0">
                                            <div class="px-2 pt-2 pb-1 text-[11px] font-black uppercase tracking-widest text-slate-400">Invited</div>
                                            <SelectItem v-for="inv in invitations" :key="inv.id" :value="`inv:${inv.id}`" class="text-[13px] font-bold">
                                                {{ inv.email }} <span class="text-slate-400 font-normal">(Invited)</span>
                                            </SelectItem>
                                        </template>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div class="flex justify-between items-center min-h-[24px]">
                                <span class="text-slate-900 dark:text-slate-400 text-[13px]">{{ usesExternalDueDates ? 'Internal Due Date' : 'Due Date' }}</span>
                                <div class="flex items-center hover:bg-slate-100 dark:hover:bg-white/10 pl-2 pr-1 rounded transition-colors cursor-pointer mr-[-3px]">
                                    <input
                                        type="date"
                                        :value="dueAtProxy"
                                        :disabled="project.inactive"
                                        @input="$emit('update:dueAtProxy', ($event.target as HTMLInputElement).value)"
                                        :class="[
                                            'custom-date-input bg-transparent border-none p-0 text-[13px] font-black uppercase tracking-normal text-slate-900 dark:text-slate-200 focus:ring-0',
                                            project.inactive ? 'opacity-50 cursor-default' : 'cursor-pointer',
                                            !dueAtProxy ? 'w-[112px] is-empty text-left' : 'w-[112px] text-right'
                                        ]"
                                    />
                                </div>
                            </div>

                            <div v-if="usesExternalDueDates" class="flex justify-between items-center min-h-[24px]">
                                <span class="text-slate-900 dark:text-slate-400 text-[13px]">External Due Date</span>
                                <div class="flex items-center hover:bg-slate-100 dark:hover:bg-white/10 pl-2 pr-1 rounded transition-colors cursor-pointer mr-[-3px]">
                                    <input
                                        type="date"
                                        :value="item.external_due_at ? item.external_due_at.substring(0, 10) : ''"
                                        :disabled="project.inactive"
                                        @input="$emit('change', 'external_due_at', ($event.target as HTMLInputElement).value)"
                                        :class="[
                                            'custom-date-input bg-transparent border-none p-0 text-[13px] font-black uppercase tracking-normal text-slate-900 dark:text-slate-200 focus:ring-0',
                                            project.inactive ? 'opacity-50 cursor-default' : 'cursor-pointer',
                                            !item.external_due_at ? 'w-[112px] is-empty text-left' : 'w-[112px] text-right'
                                        ]"
                                    />
                                </div>
                            </div>

                            <div class="flex justify-between items-center min-h-[24px]">
                                <span class="text-slate-900 dark:text-slate-400 text-[13px]">Priority</span>
                                <Select :model-value="item.priority" :disabled="project.inactive" @update:model-value="(val) => $emit('change', 'priority', val)">
                                    <SelectTrigger class="h-auto p-0 border-none bg-transparent dark:!bg-transparent hover:bg-slate-100 dark:hover:bg-white/10 focus:bg-transparent dark:focus:bg-transparent focus-visible:ring-0 rounded-md transition-all shadow-none w-auto outline-none disabled:opacity-50 disabled:pointer-events-none">
                                        <div class="px-2 py-1">
                                            <span class="relative left-[10px] font-black uppercase tracking-[0.12em] text-slate-900 dark:text-slate-200 text-[13px] flex items-center">
                                                <SelectValue />
                                                <div :class="[priorityDotClasses[item.priority ?? 'low'], 'w-2 h-2 rounded-full ml-2 flex-shrink-0']"></div>
                                            </span>
                                        </div>
                                    </SelectTrigger>
                                    <SelectContent align="end" class="min-w-[160px]">
                                        <SelectItem v-for="(label, key) in PRIORITY_LABELS" :key="key" :value="key" class="text-[13px] font-black uppercase tracking-[0.12em] text-slate-900 dark:text-slate-200 cursor-pointer focus:bg-slate-100 dark:focus:bg-white/10">
                                            <div class="flex items-center justify-between w-full">
                                                <span>{{ label }}</span>
                                                <div :class="[priorityDotClasses[key], 'w-2 h-2 rounded-full ml-4 flex-shrink-0']"></div>
                                            </div>
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div class="flex justify-between items-center min-h-[24px]">
                                <span class="text-slate-900 dark:text-slate-400 text-[13px]">Status</span>
                                <Select :model-value="item.task_status ?? 'todo'" :disabled="project.inactive" @update:model-value="(val) => $emit('change', 'task_status', val)">
                                    <SelectTrigger class="h-auto p-0 border-none bg-transparent dark:!bg-transparent hover:bg-slate-100 dark:hover:bg-white/10 focus:bg-transparent dark:focus:bg-transparent focus-visible:ring-0 rounded-md transition-all shadow-none w-auto outline-none disabled:opacity-50 disabled:pointer-events-none">
                                        <div class="px-2 py-1">
                                            <span class="relative left-[10px] font-black uppercase tracking-[0.12em] text-slate-900 dark:text-slate-200 text-[13px] flex items-center">
                                                <SelectValue />
                                                <div :class="[kanbanDotClasses[columns.find(c => c.key === (item.task_status ?? 'todo'))?.color ?? 'slate'], 'w-2 h-2 rounded-full ml-2 flex-shrink-0']"></div>
                                            </span>
                                        </div>
                                    </SelectTrigger>
                                    <SelectContent align="end" class="min-w-[160px]">
                                        <SelectItem v-for="column in columns" :key="column.key" :value="column.key" class="text-[13px] font-black uppercase tracking-[0.12em] text-slate-900 dark:text-slate-200 cursor-pointer">
                                            <div class="flex items-center justify-between w-full min-w-[120px]">
                                                <span>{{ column.label }}</span>
                                                <div :class="[kanbanDotClasses[column.color ?? 'slate'], 'w-2 h-2 rounded-full ml-4 flex-shrink-0']"></div>
                                            </div>
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="item.id" class="pt-6 border-t border-slate-200 dark:border-white/10">
                    <h4 class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-700 dark:text-slate-500 mb-4">Dates</h4>
                    <div class="space-y-2">
                        <div class="flex items-center justify-between text-[13px]">
                            <span class="text-slate-900">Created</span>
                            <div class="flex items-center gap-1.5 font-bold">
                                <span class="text-slate-900 dark:text-slate-200">{{ formatDate(item.created_at) }}</span>
                                <span v-if="item.creator?.name" class="text-slate-400 font-medium lowercase italic">by</span>
                                <span v-if="item.creator?.name" class="text-projector-primary-600">{{ item.creator?.name }}</span>
                            </div>
                        </div>
                        <div class="flex items-center justify-between text-[13px]">
                            <span class="text-slate-900">Last Updated</span>
                            <div class="flex items-center gap-1.5 font-bold">
                                <span class="text-slate-900 dark:text-slate-200">{{ formatDate(item.updated_at) }}</span>
                                <span class="text-slate-400 font-medium lowercase italic">by</span>
                                <span v-if="item.editor?.name" class="text-projector-primary-600">{{ item.editor?.name }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="item.id" class="pt-6 border-t border-slate-200 dark:border-white/10">
                    <Button as-child variant="outline" size="sm" class="w-full">
                        <a :href="exportPdf.url({ project: project.id, document: String(item.id) })">
                            <FileDown class="h-3.5 w-3.5" />
                            Export as PDF
                        </a>
                    </Button>
                </div>
            </div>
        </div>
    </aside>
</template>

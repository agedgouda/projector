<script setup lang="ts">
import {
    exportGoogleDoc,
    exportPdf,
    exportWord,
} from '@/actions/App/Http/Controllers/DocumentController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useDocumentPresenter } from '@/composables/useDocumentPresenter';
import { mergeAssigneeOptions } from '@/lib/assignees';
import { PRIORITY_LABELS } from '@/lib/constants';
import { formatDate } from '@/lib/utils';
import axios from 'axios';
import {
    ArrowRightLeft,
    Calendar as CalendarIcon,
    FileDown,
    FileStack,
    FileType,
    RefreshCw,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';

const props = defineProps<{
    project: Project;
    item: ExtendedDocument | any;
    documentTypeCatalog?: DocumentSchemaItem[];
    dueAtProxy: string;
    startAtProxy: string;
    usesExternalDueDates?: boolean;
    isReprocessable?: boolean;
    processButtonLabel?: string;
    isProcessingLive?: boolean;
    processingMessage?: string | null;
    // Every other board in this task's subproject family — empty for a project with no
    // parent and no siblings, in which case the Board section below just doesn't render
    // (nothing to move to). See DocumentController::show().
    boardOptions?: BoardOption[];
}>();

const emit = defineEmits<{
    (e: 'change', field: string, val: any): void;
    (e: 'update:dueAtProxy', val: string): void;
    (e: 'update:startAtProxy', val: string): void;
    (e: 'request-process'): void;
    (e: 'move', targetProjectId: string): void;
}>();

const { getDocLabel, isTask } = useDocumentPresenter(props.documentTypeCatalog);
const shouldShowTask = computed(() => isTask(props.item.type));
// Events aren't tasks (no assignee/priority/status — see shouldShowTask above), but they do
// share the start/due date fields tasks use, to mark a range on the calendar. Checked by
// literal type key rather than a new schema flag — the one non-task type that currently
// wants dates, kept simple until a second one shows up needing the same thing.
const isEvent = computed(() => props.item.type === 'event');
const hasOtherBoards = computed(() => (props.boardOptions?.length ?? 0) > 0);

const assigneeValue = computed(() => {
    if (props.item.pending_assignee_invitation_id) {
        return `inv:${props.item.pending_assignee_invitation_id}`;
    }
    return props.item.assignee_id?.toString() ?? 'unassigned';
});

const columns = computed(() => props.project.kanban_columns ?? []);

// Real users and invited people merged into one alphabetically-sorted list, rather than
// users in their raw (insertion) order followed by a separate invited block.
const assigneeOptions = computed(() =>
    mergeAssigneeOptions(
        props.project.client.organization?.users,
        props.project.client.organization?.invitations,
    ),
);

// Unlike PDF/Word (plain <a href> downloads), this hits a JSON endpoint — it needs to branch
// on whether the user has a connected Google account (open the created Doc) or not (send
// them through the OAuth connect flow, then straight back here to retry automatically).
const exportingToGoogleDoc = ref(false);
const exportToGoogleDoc = async () => {
    exportingToGoogleDoc.value = true;

    try {
        const response = await axios.get<{ url: string }>(
            exportGoogleDoc({
                project: props.project.id,
                document: String(props.item.id),
            }).url,
        );
        window.open(response.data.url, '_blank');
    } catch (err) {
        if (
            axios.isAxiosError(err) &&
            err.response?.status === 428 &&
            err.response.data?.connect_url
        ) {
            const returnUrl = new URL(window.location.href);
            returnUrl.searchParams.set('google_export', 'doc');

            const connectUrl = new URL(err.response.data.connect_url);
            connectUrl.searchParams.set(
                'return_to',
                returnUrl.pathname + returnUrl.search,
            );

            window.location.href = connectUrl.toString();
            return;
        }
        toast.error(
            'Something went wrong exporting to Google Docs. Please try again.',
        );
    } finally {
        exportingToGoogleDoc.value = false;
    }
};

onMounted(() => {
    if (
        new URLSearchParams(window.location.search).get('google_export') ===
        'doc'
    ) {
        const url = new URL(window.location.href);
        url.searchParams.delete('google_export');
        window.history.replaceState(window.history.state, '', url);
        void exportToGoogleDoc();
    }
});

// The native date input's own text/icon rendering can't be pixel-matched to the Assignee
// text above it (Chrome renders a filled value's segments differently than its placeholder
// text with respect to letter-spacing/alignment). So the input itself is made invisible and
// only handles the click-to-open-picker + value interaction; the visible text is this plain
// span, styled identically to the Assignee span, which guarantees identical rendering.
const formatDateDisplay = (val: string | null | undefined): string => {
    if (!val) {
        return 'MM/DD/YYYY';
    }
    const [year, month, day] = val.split('-');
    return `${month}/${day}/${year}`;
};
</script>

<template>
    <aside class="col-span-12 lg:col-span-4">
        <div class="sticky top-10 space-y-6">
            <div
                class="space-y-8 rounded-3xl border border-slate-200 bg-slate-50 p-8 dark:border-white/10 dark:bg-white/5"
            >
                <div>
                    <h4
                        class="mb-4 text-[11px] font-black tracking-[0.2em] text-slate-700 uppercase dark:text-slate-500"
                    >
                        Properties
                    </h4>

                    <div class="space-y-3">
                        <div
                            class="flex items-center justify-between text-[13px]"
                        >
                            <span class="text-slate-900">Category</span>
                            <span
                                class="rounded border border-projector-primary-100 bg-projector-primary-50 px-2 py-0.5 text-[11px] font-black tracking-wider text-projector-primary-600 uppercase dark:border-projector-primary-800 dark:bg-projector-primary-950 dark:text-projector-primary-400"
                            >
                                {{ getDocLabel(item.type) || 'New Document' }}
                            </span>
                        </div>

                        <Button
                            v-if="isReprocessable && !isProcessingLive"
                            variant="outline"
                            size="sm"
                            class="w-full"
                            @click="$emit('request-process')"
                        >
                            {{ processButtonLabel }}
                            {{ getDocLabel(item.type) || 'Document' }}
                        </Button>

                        <div
                            v-else-if="isProcessingLive"
                            class="flex h-8 w-full items-center justify-center gap-1.5 text-[13px] text-projector-primary-600 dark:text-projector-primary-400"
                        >
                            <RefreshCw class="h-3.5 w-3.5 animate-spin" />
                            <span class="animate-pulse">{{
                                processingMessage || 'Processing...'
                            }}</span>
                        </div>

                        <div class="flex flex-col" v-if="shouldShowTask">
                            <div
                                class="flex min-h-[24px] items-center justify-between"
                            >
                                <span
                                    class="text-[13px] text-slate-900 dark:text-slate-400"
                                    >Assignee</span
                                >
                                <Select
                                    :model-value="assigneeValue"
                                    :disabled="project.inactive"
                                    @update:model-value="
                                        (val) =>
                                            $emit('change', 'assignee_id', val)
                                    "
                                >
                                    <SelectTrigger
                                        class="h-auto w-auto rounded-md border-none bg-transparent p-0 shadow-none transition-all outline-none hover:bg-slate-100 focus:bg-transparent focus-visible:ring-0 disabled:pointer-events-none disabled:opacity-50 dark:!bg-transparent dark:hover:bg-white/10 dark:focus:bg-transparent"
                                    >
                                        <div class="px-2 py-1">
                                            <span
                                                class="relative left-[10px] text-[13px] font-black tracking-[0.12em] text-slate-900 uppercase dark:text-slate-200"
                                                ><SelectValue
                                            /></span>
                                        </div>
                                    </SelectTrigger>
                                    <SelectContent
                                        align="end"
                                        class="min-w-[200px]"
                                    >
                                        <SelectItem
                                            value="unassigned"
                                            class="text-[13px] font-bold text-slate-400 uppercase"
                                            >Unassigned</SelectItem
                                        >
                                        <SelectItem
                                            v-for="option in assigneeOptions"
                                            :key="option.value"
                                            :value="option.value"
                                            class="text-[13px] font-bold uppercase"
                                            >{{ option.label }}</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                            </div>

                            <div
                                class="flex min-h-[24px] items-center justify-between"
                            >
                                <span
                                    class="text-[13px] text-slate-900 dark:text-slate-400"
                                    >{{
                                        usesExternalDueDates
                                            ? 'Internal Due Date'
                                            : 'Due Date'
                                    }}</span
                                >
                                <div
                                    class="relative flex items-center gap-1.5 rounded transition-colors hover:bg-slate-100 dark:hover:bg-white/10"
                                    :class="
                                        project.inactive ? 'opacity-50' : ''
                                    "
                                >
                                    <span
                                        class="pointer-events-none w-[112px] text-right text-[13px] font-black tracking-[0.12em] text-slate-900 uppercase dark:text-slate-200"
                                    >
                                        {{ formatDateDisplay(dueAtProxy) }}
                                    </span>
                                    <CalendarIcon
                                        class="pointer-events-none h-4 w-4 shrink-0 text-slate-400"
                                    />
                                    <input
                                        type="date"
                                        :value="dueAtProxy"
                                        :disabled="project.inactive"
                                        @input="
                                            $emit(
                                                'update:dueAtProxy',
                                                (
                                                    $event.target as HTMLInputElement
                                                ).value,
                                            )
                                        "
                                        :class="[
                                            'custom-date-input absolute inset-0 h-full w-full border-none p-0 opacity-0',
                                            project.inactive
                                                ? 'cursor-default'
                                                : 'cursor-pointer',
                                        ]"
                                    />
                                </div>
                            </div>

                            <div
                                v-if="usesExternalDueDates"
                                class="flex min-h-[24px] items-center justify-between"
                            >
                                <span
                                    class="text-[13px] text-slate-900 dark:text-slate-400"
                                    >External Due Date</span
                                >
                                <div
                                    class="relative flex items-center gap-1.5 rounded transition-colors hover:bg-slate-100 dark:hover:bg-white/10"
                                    :class="
                                        project.inactive ? 'opacity-50' : ''
                                    "
                                >
                                    <span
                                        class="pointer-events-none w-[112px] text-right text-[13px] font-black tracking-[0.12em] text-slate-900 uppercase dark:text-slate-200"
                                    >
                                        {{
                                            formatDateDisplay(
                                                item.external_due_at
                                                    ? item.external_due_at.substring(
                                                          0,
                                                          10,
                                                      )
                                                    : '',
                                            )
                                        }}
                                    </span>
                                    <CalendarIcon
                                        class="pointer-events-none h-4 w-4 shrink-0 text-slate-400"
                                    />
                                    <input
                                        type="date"
                                        :value="
                                            item.external_due_at
                                                ? item.external_due_at.substring(
                                                      0,
                                                      10,
                                                  )
                                                : ''
                                        "
                                        :disabled="project.inactive"
                                        @input="
                                            $emit(
                                                'change',
                                                'external_due_at',
                                                (
                                                    $event.target as HTMLInputElement
                                                ).value,
                                            )
                                        "
                                        :class="[
                                            'custom-date-input absolute inset-0 h-full w-full border-none p-0 opacity-0',
                                            project.inactive
                                                ? 'cursor-default'
                                                : 'cursor-pointer',
                                        ]"
                                    />
                                </div>
                            </div>

                            <div
                                class="flex min-h-[24px] items-center justify-between"
                            >
                                <span
                                    class="text-[13px] text-slate-900 dark:text-slate-400"
                                    >Priority</span
                                >
                                <Select
                                    :model-value="item.priority"
                                    :disabled="project.inactive"
                                    @update:model-value="
                                        (val) =>
                                            $emit('change', 'priority', val)
                                    "
                                >
                                    <SelectTrigger
                                        class="h-auto w-auto rounded-md border-none bg-transparent p-0 shadow-none transition-all outline-none hover:bg-slate-100 focus:bg-transparent focus-visible:ring-0 disabled:pointer-events-none disabled:opacity-50 dark:!bg-transparent dark:hover:bg-white/10 dark:focus:bg-transparent"
                                    >
                                        <div class="px-2 py-1">
                                            <span
                                                class="relative left-[10px] flex items-center text-[13px] font-black tracking-[0.12em] text-slate-900 uppercase dark:text-slate-200"
                                            >
                                                <SelectValue />
                                            </span>
                                        </div>
                                    </SelectTrigger>
                                    <SelectContent
                                        align="end"
                                        class="min-w-[160px]"
                                    >
                                        <SelectItem
                                            v-for="(
                                                label, key
                                            ) in PRIORITY_LABELS"
                                            :key="key"
                                            :value="key"
                                            class="cursor-pointer text-[13px] font-black tracking-[0.12em] text-slate-900 uppercase focus:bg-slate-100 dark:text-slate-200 dark:focus:bg-white/10"
                                        >
                                            {{ label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div
                                class="flex min-h-[24px] items-center justify-between"
                            >
                                <span
                                    class="text-[13px] text-slate-900 dark:text-slate-400"
                                    >Status</span
                                >
                                <Select
                                    :model-value="item.task_status ?? 'todo'"
                                    :disabled="project.inactive"
                                    @update:model-value="
                                        (val) =>
                                            $emit('change', 'task_status', val)
                                    "
                                >
                                    <SelectTrigger
                                        class="h-auto w-auto rounded-md border-none bg-transparent p-0 shadow-none transition-all outline-none hover:bg-slate-100 focus:bg-transparent focus-visible:ring-0 disabled:pointer-events-none disabled:opacity-50 dark:!bg-transparent dark:hover:bg-white/10 dark:focus:bg-transparent"
                                    >
                                        <div class="px-2 py-1">
                                            <span
                                                class="relative left-[10px] flex items-center text-[13px] font-black tracking-[0.12em] text-slate-900 uppercase dark:text-slate-200"
                                            >
                                                <SelectValue />
                                            </span>
                                        </div>
                                    </SelectTrigger>
                                    <SelectContent
                                        align="end"
                                        class="min-w-[160px]"
                                    >
                                        <SelectItem
                                            v-for="column in columns"
                                            :key="column.key"
                                            :value="column.key"
                                            class="cursor-pointer text-[13px] font-black tracking-[0.12em] text-slate-900 uppercase dark:text-slate-200"
                                        >
                                            {{ column.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div
                                v-if="hasOtherBoards"
                                class="flex min-h-[24px] items-center justify-between"
                            >
                                <span
                                    class="text-[13px] text-slate-900 dark:text-slate-400"
                                    >Board</span
                                >
                                <DropdownMenu>
                                    <DropdownMenuTrigger
                                        as-child
                                        :disabled="project.inactive"
                                    >
                                        <button
                                            type="button"
                                            class="flex items-center gap-1.5 rounded-md px-2 py-1 transition-all hover:bg-slate-100 disabled:pointer-events-none disabled:opacity-50 dark:hover:bg-white/10"
                                            :disabled="project.inactive"
                                        >
                                            <span
                                                class="text-[13px] font-black tracking-[0.12em] text-slate-900 uppercase dark:text-slate-200"
                                                >{{ project.name }}</span
                                            >
                                            <ArrowRightLeft
                                                class="h-3 w-3 text-slate-400"
                                            />
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="min-w-[200px]"
                                    >
                                        <div
                                            class="px-2 py-1.5 text-[10px] font-black tracking-widest text-slate-400 uppercase"
                                        >
                                            Move to
                                        </div>
                                        <DropdownMenuItem
                                            v-for="option in boardOptions"
                                            :key="option.id"
                                            class="cursor-pointer text-[13px] font-bold"
                                            @click="emit('move', option.id)"
                                        >
                                            {{ option.name }}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>

                        <div class="flex flex-col" v-if="isEvent">
                            <div
                                class="flex min-h-[24px] items-center justify-between"
                            >
                                <span
                                    class="text-[13px] text-slate-900 dark:text-slate-400"
                                    >Start Date</span
                                >
                                <div
                                    class="relative flex items-center gap-1.5 rounded transition-colors hover:bg-slate-100 dark:hover:bg-white/10"
                                    :class="
                                        project.inactive ? 'opacity-50' : ''
                                    "
                                >
                                    <span
                                        class="pointer-events-none w-[112px] text-right text-[13px] font-black tracking-[0.12em] text-slate-900 uppercase dark:text-slate-200"
                                    >
                                        {{ formatDateDisplay(startAtProxy) }}
                                    </span>
                                    <CalendarIcon
                                        class="pointer-events-none h-4 w-4 shrink-0 text-slate-400"
                                    />
                                    <input
                                        type="date"
                                        :value="startAtProxy"
                                        :disabled="project.inactive"
                                        @input="
                                            $emit(
                                                'update:startAtProxy',
                                                (
                                                    $event.target as HTMLInputElement
                                                ).value,
                                            )
                                        "
                                        :class="[
                                            'custom-date-input absolute inset-0 h-full w-full border-none p-0 opacity-0',
                                            project.inactive
                                                ? 'cursor-default'
                                                : 'cursor-pointer',
                                        ]"
                                    />
                                </div>
                            </div>

                            <div
                                class="flex min-h-[24px] items-center justify-between"
                            >
                                <span
                                    class="text-[13px] text-slate-900 dark:text-slate-400"
                                    >End Date</span
                                >
                                <div
                                    class="relative flex items-center gap-1.5 rounded transition-colors hover:bg-slate-100 dark:hover:bg-white/10"
                                    :class="
                                        project.inactive ? 'opacity-50' : ''
                                    "
                                >
                                    <span
                                        class="pointer-events-none w-[112px] text-right text-[13px] font-black tracking-[0.12em] text-slate-900 uppercase dark:text-slate-200"
                                    >
                                        {{ formatDateDisplay(dueAtProxy) }}
                                    </span>
                                    <CalendarIcon
                                        class="pointer-events-none h-4 w-4 shrink-0 text-slate-400"
                                    />
                                    <input
                                        type="date"
                                        :value="dueAtProxy"
                                        :disabled="project.inactive"
                                        @input="
                                            $emit(
                                                'update:dueAtProxy',
                                                (
                                                    $event.target as HTMLInputElement
                                                ).value,
                                            )
                                        "
                                        :class="[
                                            'custom-date-input absolute inset-0 h-full w-full border-none p-0 opacity-0',
                                            project.inactive
                                                ? 'cursor-default'
                                                : 'cursor-pointer',
                                        ]"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-if="item.id"
                    class="border-t border-slate-200 pt-6 dark:border-white/10"
                >
                    <h4
                        class="mb-4 text-[11px] font-black tracking-[0.2em] text-slate-700 uppercase dark:text-slate-500"
                    >
                        Dates
                    </h4>
                    <div class="space-y-2">
                        <div
                            class="flex items-center justify-between text-[13px]"
                        >
                            <span class="text-slate-900">Created</span>
                            <div class="flex items-center gap-1.5 font-bold">
                                <span
                                    class="text-slate-900 dark:text-slate-200"
                                    >{{ formatDate(item.created_at) }}</span
                                >
                                <span
                                    v-if="item.creator?.name"
                                    class="font-medium text-slate-400 lowercase italic"
                                    >by</span
                                >
                                <span
                                    v-if="item.creator?.name"
                                    class="text-projector-primary-600"
                                    >{{ item.creator?.name }}</span
                                >
                            </div>
                        </div>
                        <div
                            class="flex items-center justify-between text-[13px]"
                        >
                            <span class="text-slate-900">Last Updated</span>
                            <div class="flex items-center gap-1.5 font-bold">
                                <span
                                    class="text-slate-900 dark:text-slate-200"
                                    >{{ formatDate(item.updated_at) }}</span
                                >
                                <span
                                    v-if="item.editor?.name"
                                    class="font-medium text-slate-400 lowercase italic"
                                    >by</span
                                >
                                <span
                                    v-if="item.editor?.name"
                                    class="text-projector-primary-600"
                                    >{{ item.editor?.name }}</span
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-if="item.id"
                    class="space-y-2 border-t border-slate-200 pt-6 dark:border-white/10"
                >
                    <Button as-child variant="outline" size="sm" class="w-full">
                        <a
                            :href="
                                exportPdf.url({
                                    project: project.id,
                                    document: String(item.id),
                                })
                            "
                        >
                            <FileDown class="h-3.5 w-3.5" />
                            Export As PDF
                        </a>
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="w-full"
                        :disabled="exportingToGoogleDoc"
                        @click="exportToGoogleDoc"
                    >
                        <FileStack class="h-3.5 w-3.5" />
                        {{
                            exportingToGoogleDoc
                                ? 'Exporting…'
                                : 'Export To Google Docs'
                        }}
                    </Button>
                    <Button as-child variant="outline" size="sm" class="w-full">
                        <a
                            :href="
                                exportWord.url({
                                    project: project.id,
                                    document: String(item.id),
                                })
                            "
                        >
                            <FileType class="h-3.5 w-3.5" />
                            Export As Word
                        </a>
                    </Button>
                </div>
            </div>
        </div>
    </aside>
</template>

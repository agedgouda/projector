<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import taskListRoutes from '@/routes/projects/task-lists';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        open: boolean;
        projectId: string;
        originalFilename: string | null;
        headers: string[];
        rows: string[][];
        suggestedMapping: Record<string, string | null>;
        defaultListType?: 'task' | 'event';
    }>(),
    { defaultListType: 'task' },
);

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'started'): void;
}>();

// A <Select> can't use an empty string as a real value (Radix reserves it for "no selection"
// display), so an unmapped field is represented by this sentinel locally and converted back to
// null only when building the request payload.
const IGNORE = '__ignore__';

type FieldKey =
    | 'name'
    | 'priority'
    | 'task_status'
    | 'due_at'
    | 'assignee'
    | 'start_date'
    | 'description'
    | 'tag';

const TASK_FIELDS: { key: FieldKey; label: string; required?: boolean }[] = [
    { key: 'name', label: 'Task Name', required: true },
    { key: 'priority', label: 'Priority' },
    { key: 'task_status', label: 'Status' },
    { key: 'due_at', label: 'Due Date' },
    { key: 'assignee', label: 'Assignee' },
    { key: 'tag', label: 'Tag' },
];

// Events don't have priority/status/assignee — they have a start and end date instead of a
// single due date, and an optional description. Due date doubles as "End Date" here since it's
// the same underlying field the calendar and the "Notes to Events" transformation both use.
// Tag matches an existing project tag by name (like the AI transformation does) — it never
// creates a new one, and only the first tag column value per row is used since events can only
// carry a single tag.
const EVENT_FIELDS: { key: FieldKey; label: string; required?: boolean }[] = [
    { key: 'name', label: 'Event Name', required: true },
    { key: 'description', label: 'Description' },
    { key: 'start_date', label: 'Start Date' },
    { key: 'due_at', label: 'End Date' },
    { key: 'tag', label: 'Tag' },
];

// Seeded from defaultListType directly (not just left at a hardcoded 'task') because this
// component only mounts once analysis() has already resolved (`v-if="analysis"` in
// ImportTaskListOptions.vue), at which point `open` is already true on the very first render —
// the watch() below (no `immediate`, intentionally, so it doesn't fight manual toggling while
// the modal stays open) only re-applies the default on a later close→reopen, not this first one.
const listType = ref<'task' | 'event'>(props.defaultListType);
const FIELDS = computed(() =>
    listType.value === 'task' ? TASK_FIELDS : EVENT_FIELDS,
);

const mapping = ref<Record<string, string>>({});

// analyze() always treats the sheet's first row as headers. When the file genuinely has none,
// that row is really data — checking this puts it back into the row set and swaps the mapping
// selects over to synthetic "Column N" labels instead of (what would otherwise be) the first
// task's own field values masquerading as column names. Named (and defaulted) to match the
// checkbox's own label, not the more common "hasHeaderRow" framing — binding a "has" flag
// directly to a "doesn't have" checkbox inverts the checked state from what the label says.
const noHeaderRow = ref(false);

const effectiveHeaders = computed(() =>
    noHeaderRow.value
        ? props.headers.map((_, index) => `Column ${index + 1}`)
        : props.headers,
);

const effectiveRows = computed(() =>
    noHeaderRow.value ? [props.headers, ...props.rows] : props.rows,
);

// Reset to the server's best-guess mapping every time a fresh analysis comes in (a new file
// picked, or the modal reopened) rather than carrying over edits from a previous file. Toggling
// "no header row" also resets the mapping — the suggested mapping was matched against real
// header text, which no longer applies once the selects switch to synthetic column labels.
// Switching between Task/Event list also resets it — the two share a mostly disjoint field set.
watch(
    () =>
        [
            props.open,
            props.headers,
            props.suggestedMapping,
            noHeaderRow.value,
            listType.value,
        ] as const,
    () => {
        if (!props.open) return;

        const initial: Record<string, string> = {};
        for (const field of FIELDS.value) {
            initial[field.key] = noHeaderRow.value
                ? IGNORE
                : (props.suggestedMapping[field.key] ?? IGNORE);
        }
        mapping.value = initial;
    },
    { immediate: true },
);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            noHeaderRow.value = false;
            listType.value = props.defaultListType;
        }
    },
);

const previewRows = computed(() => effectiveRows.value.slice(0, 5));

const columnValue = (row: string[], field: string): string => {
    const header = mapping.value[field];
    if (!header || header === IGNORE) return '—';

    const index = effectiveHeaders.value.indexOf(header);
    if (index === -1) return '—';

    return row[index]?.trim() || '—';
};

const canSubmit = computed(
    () => mapping.value.name !== IGNORE && mapping.value.name !== undefined,
);

// Kicks off the import and returns immediately once the (fast) task_list_import/
// event_list_import record exists and the real row-by-row work has been handed to a queued
// job — the actual import runs in the background from here, reported via this project's
// TaskListImportProgress broadcasts (see useTaskListImportProgress.ts, consumed by
// Projects/Show.vue's top-of-page AiProcessingHeader), not by this request.
const submit = () => {
    const mappingPayload = Object.fromEntries(
        FIELDS.value.map((field) => [
            field.key,
            mapping.value[field.key] === IGNORE
                ? null
                : mapping.value[field.key],
        ]),
    );

    // Fire both immediately, before the request even goes out — canSubmit already guarantees
    // a name column is mapped, so there's nothing left worth waiting on-screen for here. The
    // top-of-page banner (see useTaskListImportProgress.ts) takes over from 'started' onward.
    emit('started');
    emit('close');

    axios
        .post(taskListRoutes.store.url(props.projectId), {
            list_type: listType.value,
            original_filename: props.originalFilename,
            headers: effectiveHeaders.value,
            rows: effectiveRows.value,
            mapping: mappingPayload,
        })
        .catch((err) => {
            // The modal is already closed by this point, so there's no field left to attach
            // a validation message to — this is only ever an unexpected failure (canSubmit
            // already rules out the missing-name-mapping case client-side).
            console.error('Failed to start list import', err);
        });
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('close')">
        <DialogContent class="sm:max-w-[640px]">
            <DialogHeader>
                <DialogTitle>Confirm List Import</DialogTitle>
                <DialogDescription>
                    {{ effectiveRows.length }} row{{
                        effectiveRows.length === 1 ? '' : 's'
                    }}
                    found{{
                        originalFilename ? ` in ${originalFilename}` : ''
                    }}. Choose what you're importing, then match each field to a
                    column.
                </DialogDescription>
            </DialogHeader>

            <div class="grid grid-cols-[120px_1fr] items-center gap-3">
                <Label
                    class="text-[11px] font-black tracking-widest text-gray-500 uppercase"
                >
                    Import As
                </Label>
                <div class="flex gap-2">
                    <Button
                        type="button"
                        size="sm"
                        :variant="listType === 'task' ? 'default' : 'outline'"
                        class="h-8 flex-1 text-[11px] font-bold"
                        @click="listType = 'task'"
                    >
                        Task List
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :variant="listType === 'event' ? 'default' : 'outline'"
                        class="h-8 flex-1 text-[11px] font-bold"
                        @click="listType = 'event'"
                    >
                        Event List
                    </Button>
                </div>
            </div>

            <Label class="flex items-center gap-2 text-xs text-gray-500">
                <Checkbox v-model="noHeaderRow" />
                My file doesn't have a header row
            </Label>

            <div class="space-y-3">
                <div
                    v-for="field in FIELDS"
                    :key="field.key"
                    class="grid grid-cols-[120px_1fr] items-center gap-3"
                >
                    <Label
                        class="text-[11px] font-black tracking-widest text-gray-500 uppercase"
                    >
                        {{ field.label
                        }}<span v-if="field.required" class="text-red-500"
                            >*</span
                        >
                    </Label>
                    <Select v-model="mapping[field.key]">
                        <SelectTrigger class="h-9 rounded-md">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="IGNORE" class="text-gray-400"
                                >Not mapped</SelectItem
                            >
                            <SelectItem
                                v-for="header in effectiveHeaders"
                                :key="header"
                                :value="header"
                            >
                                {{ header }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <div v-if="previewRows.length" class="space-y-2">
                <p
                    class="text-[10px] font-black tracking-widest text-gray-400 uppercase"
                >
                    Preview
                </p>
                <div
                    class="overflow-x-auto rounded-md border border-gray-200 dark:border-gray-700"
                >
                    <table class="w-full text-xs">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th
                                    v-for="field in FIELDS"
                                    :key="field.key"
                                    class="px-3 py-2 text-left font-bold text-gray-500"
                                >
                                    {{ field.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, index) in previewRows"
                                :key="index"
                                class="border-t border-gray-100 dark:border-gray-800"
                            >
                                <td
                                    v-for="field in FIELDS"
                                    :key="field.key"
                                    class="px-3 py-2 text-gray-700 dark:text-gray-300"
                                >
                                    {{ columnValue(row, field.key) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-if="effectiveRows.length > previewRows.length"
                    class="text-[11px] text-gray-400"
                >
                    …and {{ effectiveRows.length - previewRows.length }} more
                    row{{
                        effectiveRows.length - previewRows.length === 1
                            ? ''
                            : 's'
                    }}.
                </p>
            </div>

            <DialogFooter class="gap-2 sm:gap-4">
                <Button variant="outline" @click="emit('close')">
                    Cancel
                </Button>
                <Button :disabled="!canSubmit" @click="submit">
                    Import {{ effectiveRows.length }}
                    {{ listType === 'task' ? 'Task' : 'Event'
                    }}{{ effectiveRows.length === 1 ? '' : 's' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

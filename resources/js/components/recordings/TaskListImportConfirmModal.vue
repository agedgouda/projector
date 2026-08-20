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
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    projectId: string;
    originalFilename: string | null;
    headers: string[];
    rows: string[][];
    suggestedMapping: Record<string, string | null>;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'imported'): void;
}>();

// A <Select> can't use an empty string as a real value (Radix reserves it for "no selection"
// display), so an unmapped field is represented by this sentinel locally and converted back to
// null only when building the request payload.
const IGNORE = '__ignore__';

const FIELDS: {
    key: 'name' | 'priority' | 'task_status' | 'due_at' | 'assignee';
    label: string;
    required?: boolean;
}[] = [
    { key: 'name', label: 'Task Name', required: true },
    { key: 'priority', label: 'Priority' },
    { key: 'task_status', label: 'Status' },
    { key: 'due_at', label: 'Due Date' },
    { key: 'assignee', label: 'Assignee' },
];

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
watch(
    () =>
        [
            props.open,
            props.headers,
            props.suggestedMapping,
            noHeaderRow.value,
        ] as const,
    () => {
        if (!props.open) return;

        const initial: Record<string, string> = {};
        for (const field of FIELDS) {
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
        if (isOpen) noHeaderRow.value = false;
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

const form = useForm({
    original_filename: null as string | null,
    headers: [] as string[],
    rows: [] as string[][],
    mapping: {} as Record<string, string | null>,
});

const canSubmit = computed(
    () => mapping.value.name !== IGNORE && mapping.value.name !== undefined,
);

const submit = () => {
    form.original_filename = props.originalFilename;
    form.headers = effectiveHeaders.value;
    form.rows = effectiveRows.value;
    form.mapping = Object.fromEntries(
        FIELDS.map((field) => [
            field.key,
            mapping.value[field.key] === IGNORE
                ? null
                : mapping.value[field.key],
        ]),
    );

    form.post(taskListRoutes.store.url(props.projectId), {
        onSuccess: () => emit('imported'),
    });
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('close')">
        <DialogContent class="sm:max-w-[640px]">
            <DialogHeader>
                <DialogTitle>Confirm Task List Import</DialogTitle>
                <DialogDescription>
                    {{ effectiveRows.length }} row{{
                        effectiveRows.length === 1 ? '' : 's'
                    }}
                    found{{
                        originalFilename ? ` in ${originalFilename}` : ''
                    }}. Match each field to a column before importing.
                </DialogDescription>
            </DialogHeader>

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

                <p
                    v-if="form.errors['mapping.name']"
                    class="text-xs text-destructive"
                >
                    {{ form.errors['mapping.name'] }}
                </p>
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
                <Button
                    variant="outline"
                    :disabled="form.processing"
                    @click="emit('close')"
                >
                    Cancel
                </Button>
                <Button
                    :disabled="form.processing || !canSubmit"
                    @click="submit"
                >
                    {{
                        form.processing
                            ? 'Importing...'
                            : `Import ${effectiveRows.length} Task${effectiveRows.length === 1 ? '' : 's'}`
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { fieldsForListType, IGNORE } from '@/lib/taskListImportFields';
import { X } from 'lucide-vue-next';
import { computed } from 'vue';

// One "pass" turns some or all of the same uploaded sheet into one record type — Tasks and
// Events stay fully separate documents with no relationship between them in the database, even
// when (as is expected) the same row feeds both a task pass and an event pass. See
// ImportTransformationModal.vue, which renders one of these per detected/saved pass.
const props = defineProps<{
    listType: 'task' | 'event';
    headers: string[];
    rows: string[][];
    mapping: Record<string, string>;
    rationale?: string | null;
    removable?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:mapping', value: Record<string, string>): void;
    (e: 'remove'): void;
}>();

const FIELDS = computed(() => fieldsForListType(props.listType));

const setField = (key: string, value: string) => {
    emit('update:mapping', { ...props.mapping, [key]: value });
};

const previewRows = computed(() => props.rows.slice(0, 3));

const columnValue = (row: string[], field: string): string => {
    const header = props.mapping[field];
    if (!header || header === IGNORE) return '—';

    const index = props.headers.indexOf(header);
    if (index === -1) return '—';

    return row[index]?.trim() || '—';
};
</script>

<template>
    <div
        class="space-y-4 rounded-xl border border-gray-200 p-4 dark:border-gray-700"
    >
        <div class="flex items-start justify-between gap-3">
            <div>
                <p
                    class="text-[10px] font-black tracking-widest text-gray-400 uppercase"
                >
                    {{ listType === 'task' ? 'Task' : 'Event' }} Pass
                </p>
                <p
                    v-if="rationale"
                    class="mt-0.5 text-xs text-gray-500 dark:text-gray-400"
                >
                    {{ rationale }}
                </p>
            </div>
            <Button
                v-if="removable"
                variant="ghost"
                size="icon"
                class="h-7 w-7 shrink-0 text-gray-400 hover:text-red-500"
                @click="emit('remove')"
            >
                <X class="h-4 w-4" />
            </Button>
        </div>

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
                    }}<span v-if="field.required" class="text-red-500">*</span>
                </Label>
                <Select
                    :model-value="mapping[field.key] ?? IGNORE"
                    @update:model-value="
                        (v) => setField(field.key, v as string)
                    "
                >
                    <SelectTrigger class="h-9 rounded-md">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="IGNORE" class="text-gray-400"
                            >Not mapped</SelectItem
                        >
                        <SelectItem
                            v-for="header in headers"
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
        </div>
    </div>
</template>

<script setup lang="ts">
import { Calendar as CalendarIcon } from 'lucide-vue-next';
import { parseDate, type DateValue } from '@internationalized/date';
import { computed, ref, watch } from 'vue';
import { Calendar } from '@/components/ui/calendar';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

// A single shared date-editing control, replacing the native `<input type="date">` used
// (each with its own copy of the same shrink-the-icon or overlay-an-invisible-input
// workarounds) across TaskRowFields/KanbanCard/DocumentDetailSheet/DocumentSidebar/
// TaskReportTable/TaskSearchForm — see project memory for why. Value contract matches what
// those native inputs already gave callers (an ISO 'YYYY-MM-DD' string, or '' when cleared),
// so this drops in without changing any @change/@update handler's own null-conversion logic.
const props = withDefaults(
    defineProps<{
        modelValue: string | null | undefined;
        disabled?: boolean;
        placeholder?: string;
        // How the built-in trigger (ignored if the default slot is overridden) renders a
        // non-empty value — 'iso' preserves what every dense-row usage already showed
        // (e.g. TaskRowFields' read-only span next to this same field), 'mdy' matches
        // DocumentSidebar's own formatDateDisplay().
        format?: 'iso' | 'mdy';
        showIcon?: boolean;
        iconClass?: string;
        triggerClass?: string;
        align?: 'start' | 'center' | 'end';
        // Only applied to the built-in trigger button, for a <label for> to target — a custom
        // trigger passed via the default slot places its own id wherever that markup needs it.
        id?: string;
    }>(),
    {
        disabled: false,
        placeholder: '--',
        format: 'iso',
        showIcon: true,
        align: 'start',
    },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const open = ref(false);

// The calendar grid needs a real DateValue, not a plain ISO string — parsed defensively
// since an unset field (empty/null modelValue) is the common case, not an error.
const calendarValue = computed(() => {
    if (!props.modelValue) return undefined;
    try {
        return parseDate(props.modelValue);
    } catch {
        return undefined;
    }
});

const selectCalendarDate = (value: DateValue | DateValue[] | undefined) => {
    if (Array.isArray(value)) return; // Never happens — Calendar isn't given `multiple`.
    if (!value) return;
    emit('update:modelValue', value.toString());
    open.value = false;
};

// Edited independently of modelValue until Enter/blur commits a valid date — a v-model
// bound straight to the parsed value would fight every keystroke of a partially-typed date.
const textValue = ref(props.modelValue ?? '');
watch(
    () => props.modelValue,
    (value) => {
        textValue.value = value ?? '';
    },
);

const mdyToIso = (raw: string): string => {
    const match = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (!match) throw new Error('Not a recognized date');
    const [, month, day, year] = match;
    return `${year}-${month.padStart(2, '0')}-${day.padStart(2, '0')}`;
};

const commitText = () => {
    const raw = textValue.value.trim();
    if (raw === '') {
        emit('update:modelValue', '');
        return;
    }
    try {
        const iso = /^\d{4}-\d{2}-\d{2}$/.test(raw) ? raw : mdyToIso(raw);
        parseDate(iso); // Throws on a calendar date that doesn't exist (e.g. Feb 30).
        emit('update:modelValue', iso);
    } catch {
        textValue.value = props.modelValue ?? ''; // Revert rather than emit garbage.
    }
};

const displayValue = computed(() => {
    if (!props.modelValue) return props.placeholder;
    if (props.format === 'iso') return props.modelValue;

    const [year, month, day] = props.modelValue.split('-');
    return `${month}/${day}/${year}`;
});
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child :disabled="disabled">
            <slot :display="displayValue" :open="open">
                <button
                    type="button"
                    :id="id"
                    :disabled="disabled"
                    :class="cn(
                        'inline-flex items-center gap-1.5 disabled:cursor-not-allowed disabled:opacity-50',
                        triggerClass,
                    )"
                >
                    <CalendarIcon v-if="showIcon" :class="cn('h-3.5 w-3.5 shrink-0 text-slate-400', iconClass)" />
                    <span>{{ displayValue }}</span>
                </button>
            </slot>
        </PopoverTrigger>
        <PopoverContent :align="align" class="w-auto space-y-2 p-3">
            <Input
                v-model="textValue"
                placeholder="MM/DD/YYYY"
                class="h-8 text-[13px]"
                @keydown.enter="commitText"
                @blur="commitText"
            />
            <Calendar :model-value="calendarValue" @update:model-value="selectCalendarDate" />
        </PopoverContent>
    </Popover>
</template>

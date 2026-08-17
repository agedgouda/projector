<script setup lang="ts">
import { computed, ref } from 'vue';
import { Check, ChevronsUpDown } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

export interface MultiSelectOption {
    value: string;
    label: string;
}

const props = defineProps<{
    modelValue: string[];
    options: MultiSelectOption[];
    placeholder: string;
    searchPlaceholder?: string;
    emptyText?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string[]];
}>();

const open = ref(false);
const search = ref('');

const filteredOptions = computed(() => {
    const query = search.value.trim().toLowerCase();
    if (!query) return props.options;
    return props.options.filter((option) => option.label.toLowerCase().includes(query));
});

const isSelected = (value: string) => props.modelValue.includes(value);

const toggle = (value: string) => {
    const next = isSelected(value) ? props.modelValue.filter((v) => v !== value) : [...props.modelValue, value];
    emit('update:modelValue', next);
};

// Stays flush with modelValue rather than the raw label text, so a filter restored from the
// URL (e.g. an assignee who's since been removed from the org) still shows a count instead of
// silently rendering nothing.
const triggerText = computed(() => {
    if (props.modelValue.length === 0) return props.placeholder;
    if (props.modelValue.length === 1) {
        return props.options.find((option) => option.value === props.modelValue[0])?.label ?? props.placeholder;
    }
    return `${props.modelValue.length} selected`;
});
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                variant="outline"
                role="combobox"
                :aria-expanded="open"
                type="button"
                class="h-9 w-full justify-between font-normal text-[13px]"
                :class="modelValue.length === 0 && 'text-muted-foreground'"
            >
                <span class="truncate">{{ triggerText }}</span>
                <ChevronsUpDown class="ml-2 h-3.5 w-3.5 shrink-0 opacity-50" />
            </Button>
        </PopoverTrigger>

        <PopoverContent class="w-[--radix-popover-trigger-width] p-0" align="start">
            <!-- Selecting an item never closes the popover (unlike a single-value combobox) —
                 picking several values in a row without the menu closing each time is the
                 whole point of a multi-select. -->
            <Command :filter-function="() => true">
                <CommandInput v-model="search" :placeholder="searchPlaceholder ?? 'Search…'" />
                <CommandList>
                    <CommandEmpty>{{ emptyText ?? 'No results found.' }}</CommandEmpty>
                    <CommandGroup>
                        <CommandItem
                            v-for="option in filteredOptions"
                            :key="option.value"
                            :value="option.value"
                            @select="toggle(option.value)"
                        >
                            <Check class="mr-2 h-4 w-4" :class="isSelected(option.value) ? 'opacity-100' : 'opacity-0'" />
                            {{ option.label }}
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>

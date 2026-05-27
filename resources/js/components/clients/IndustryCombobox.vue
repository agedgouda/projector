<script setup lang="ts">
import { ref, computed } from 'vue';
import { Check, ChevronsUpDown } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

const INDUSTRIES = [
    'Advertising & Marketing',
    'Architecture & Design',
    'Construction',
    'Consulting',
    'Education',
    'Entertainment & Media',
    'Fashion & Apparel',
    'Film & Video Production',
    'Financial Services',
    'Food & Beverage',
    'Government & Public Sector',
    'Healthcare',
    'Hospitality & Events',
    'Legal',
    'Logistics & Transportation',
    'Manufacturing',
    'Non-Profit',
    'Real Estate',
    'Retail',
    'Software & Technology',
    'Sports & Recreation',
    'Telecommunications',
];

const props = defineProps<{
    modelValue: string | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string | null];
}>();

const open = ref(false);
const search = ref('');

const filteredIndustries = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return INDUSTRIES;
    return INDUSTRIES.filter(i => i.toLowerCase().includes(q));
});

const showAddCustom = computed(() => {
    const q = search.value.trim();
    return q.length > 0 && !INDUSTRIES.some(i => i.toLowerCase() === q.toLowerCase());
});

const select = (value: string) => {
    emit('update:modelValue', value === props.modelValue ? null : value);
    open.value = false;
    search.value = '';
};

const addCustom = () => {
    const q = search.value.trim();
    if (q) {
        emit('update:modelValue', q);
        open.value = false;
        search.value = '';
    }
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                variant="outline"
                role="combobox"
                :aria-expanded="open"
                class="w-full justify-between font-normal h-9 px-3 text-sm"
                :class="!modelValue && 'text-muted-foreground'"
                type="button"
            >
                {{ modelValue ?? 'Select or type an industry…' }}
                <ChevronsUpDown class="ml-2 h-4 w-4 shrink-0 opacity-50" />
            </Button>
        </PopoverTrigger>

        <PopoverContent class="w-[--radix-popover-trigger-width] p-0" align="start">
            <Command :filter-function="() => true">
                <CommandInput
                    v-model="search"
                    placeholder="Search or type a custom industry…"
                />
                <CommandList>
                    <CommandEmpty v-if="!showAddCustom">No industry found.</CommandEmpty>

                    <CommandGroup>
                        <CommandItem
                            v-for="industry in filteredIndustries"
                            :key="industry"
                            :value="industry"
                            @select="select(industry)"
                        >
                            <Check
                                class="mr-2 h-4 w-4"
                                :class="modelValue === industry ? 'opacity-100' : 'opacity-0'"
                            />
                            {{ industry }}
                        </CommandItem>

                        <CommandItem
                            v-if="showAddCustom"
                            :value="search"
                            class="text-indigo-600 dark:text-indigo-400 font-medium"
                            @select="addCustom"
                        >
                            <span class="mr-2 text-sm">+</span>
                            Add "{{ search.trim() }}"
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>

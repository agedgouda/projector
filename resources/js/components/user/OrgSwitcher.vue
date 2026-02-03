<script setup lang="ts">
import {
    Check,
    ChevronsUpDown,
    Building2
} from "lucide-vue-next";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from "@/components/ui/command";
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/components/ui/popover";
import { ref } from "vue";

defineProps<{
    organizations: any[];
    currentOrg: any;
}>();

const emit = defineEmits(['switch']);
const open = ref(false);

const handleSelect = (orgId: string | number) => {
    open.value = false;
    emit('switch', orgId);
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                variant="outline"
                role="combobox"
                :aria-expanded="open"
                class="w-full md:w-[300px] justify-between h-12 rounded-xl bg-white dark:bg-zinc-900 border-gray-200 dark:border-zinc-800 shadow-sm"
            >
                <div class="flex items-center gap-3 truncate">
                    <div class="bg-indigo-500/10 p-1.5 rounded-lg">
                        <Building2 class="h-4 w-4 text-indigo-500" />
                    </div>
                    <span class="font-bold truncate text-gray-900 dark:text-white">
                        {{ currentOrg?.name }}
                    </span>
                </div>
                <ChevronsUpDown class="ml-2 h-4 w-4 shrink-0 opacity-50" />
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-[300px] p-0 rounded-xl overflow-hidden border-gray-200 dark:border-zinc-800">
            <Command>
                <CommandInput placeholder="Search organizations..." />
                <CommandList>
                    <CommandEmpty>No organization found.</CommandEmpty>
                    <CommandGroup>
                        <CommandItem
                            v-for="org in organizations"
                            :key="org.id"
                            :value="org.name"
                            @select="handleSelect(org.id)"
                            :class="cn(
                                'flex items-center justify-between py-3 cursor-pointer transition-colors',
                                org.id === currentOrg?.id ? 'bg-indigo-50/50 dark:bg-indigo-500/5' : 'hover:bg-gray-50 dark:hover:bg-zinc-800/50'
                            )"
                        >
                            <span :class="cn(
                                'font-medium transition-colors',
                                org.id === currentOrg?.id ? 'text-indigo-600 dark:text-indigo-400' : 'text-gray-700 dark:text-zinc-300'
                            )">
                                {{ org.name }}
                            </span>
                            <Check
                                v-if="org.id === currentOrg?.id"
                                class="h-4 w-4 text-indigo-600 dark:text-indigo-400"
                            />
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>

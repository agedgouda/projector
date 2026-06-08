<script setup lang="ts">
import {
    Check,
    Building2,
    Plus
} from "lucide-vue-next";
import { cn } from "@/lib/utils";
import FlatSwitcherTrigger from "@/components/FlatSwitcherTrigger.vue";
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator
} from "@/components/ui/command";
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/components/ui/popover";
import { ref, computed } from "vue";
import { Link } from "@inertiajs/vue3";
import organizationRoutes from '@/routes/organizations/index';

const props = defineProps<{
    organizations: any[];
    currentOrg: any;
}>();

const emit = defineEmits(['switch']);
const open = ref(false);

const sortedOrganizations = computed(() =>
    [...props.organizations].sort((a, b) => a.name.localeCompare(b.name))
);

const handleSelect = (orgId: string | number) => {
    open.value = false;
    emit('switch', orgId);
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <FlatSwitcherTrigger
                :icon-src="currentOrg?.logo_url"
                :icon-alt="currentOrg?.name"
                :icon-fallback="Building2"
                eyebrow="Organization"
                :title="currentOrg?.name ?? 'Select Organization'"
            />
        </PopoverTrigger>
        <PopoverContent class="w-[300px] p-0 rounded-xl overflow-hidden border-gray-200 dark:border-zinc-800">
            <Command>
                <CommandInput placeholder="Search organizations..." />
                <CommandList>
                    <CommandEmpty>No organization found.</CommandEmpty>

                    <CommandGroup>
                        <CommandItem
                            value="create-new-organization"
                            as-child
                            class="flex items-center gap-2 py-3 cursor-pointer text-projector-primary-600 dark:text-projector-primary-400 font-black uppercase text-[10px] tracking-widest"
                        >
                            <Link :href="organizationRoutes.create.url()" @click="open = false">
                                <Plus class="h-4 w-4" />
                                Create New Organization
                            </Link>
                        </CommandItem>
                    </CommandGroup>

                    <CommandSeparator />

                    <CommandGroup heading="Organizations">
                        <CommandItem
                            v-for="org in sortedOrganizations"
                            :key="org.id"
                            :value="org.name"
                            @select="handleSelect(org.id)"
                            :class="cn(
                                'flex items-center justify-between py-3 cursor-pointer transition-colors',
                                org.id === currentOrg?.id ? 'bg-projector-primary-50/50 dark:bg-projector-primary-500/5' : 'hover:bg-gray-50 dark:hover:bg-zinc-800/50'
                            )"
                        >
                            <span :class="cn(
                                'font-medium transition-colors',
                                org.id === currentOrg?.id ? 'text-projector-primary-600 dark:text-projector-primary-400' : 'text-gray-700 dark:text-zinc-300'
                            )">
                                {{ org.name }}
                            </span>
                            <Check
                                v-if="org.id === currentOrg?.id"
                                class="h-4 w-4 text-projector-primary-600 dark:text-projector-primary-400"
                            />
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>

<script setup lang="ts">
import FlatSwitcherTrigger from '@/components/FlatSwitcherTrigger.vue';
import IconTile from '@/components/IconTile.vue';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import organizationRoutes from '@/routes/organizations/index';
import { Link } from '@inertiajs/vue3';
import { Building2, Check, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{
    organizations: any[];
    currentOrg: any;
}>();

const emit = defineEmits(['switch']);
const open = ref(false);

const sortedOrganizations = computed(() =>
    [...props.organizations].sort((a, b) => a.name.localeCompare(b.name)),
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
        <PopoverContent
            class="w-[300px] overflow-hidden rounded-xl border-gray-200 p-0 dark:border-zinc-800"
        >
            <Command>
                <CommandInput placeholder="Search organizations..." />
                <CommandList>
                    <CommandEmpty>No organization found.</CommandEmpty>

                    <CommandGroup>
                        <CommandItem
                            value="create-new-organization"
                            as-child
                            class="flex cursor-pointer items-center gap-2 py-3 text-[10px] font-black tracking-widest text-projector-primary-600 uppercase dark:text-projector-primary-400"
                        >
                            <Link
                                :href="organizationRoutes.create.url()"
                                @click="open = false"
                            >
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
                            :class="
                                cn(
                                    'flex cursor-pointer items-center justify-between gap-2 py-3 transition-colors',
                                    org.id === currentOrg?.id
                                        ? 'bg-projector-primary-50/50 dark:bg-projector-primary-500/5'
                                        : 'hover:bg-gray-50 dark:hover:bg-zinc-800/50',
                                )
                            "
                        >
                            <div class="flex min-w-0 items-center gap-2">
                                <IconTile
                                    :src="org.logo_url"
                                    :alt="org.name"
                                    :icon="Building2"
                                    size="sm"
                                    tone="muted"
                                />
                                <span
                                    :class="
                                        cn(
                                            'truncate font-medium transition-colors',
                                            org.id === currentOrg?.id
                                                ? 'text-projector-primary-600 dark:text-projector-primary-400'
                                                : 'text-gray-700 dark:text-zinc-300',
                                        )
                                    "
                                >
                                    {{ org.name }}
                                </span>
                            </div>
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

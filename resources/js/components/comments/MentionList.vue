<script setup lang="ts">
import { FLAT_ROW_HOVER, FLAT_ROW_SELECTED } from '@/lib/flat-ui';
import { ref, watch } from 'vue';

const props = defineProps<{
    items: {
        id: number | string;
        name: string;
        first_name: string;
        last_name: string;
    }[];
    command: (item: { id: number | string; label: string }) => void;
}>();

const selectedIndex = ref(0);

watch(
    () => props.items,
    () => {
        selectedIndex.value = 0;
    },
);

const onKeyDown = (event: KeyboardEvent) => {
    if (event.key === 'ArrowUp') {
        selectedIndex.value =
            (selectedIndex.value - 1 + props.items.length) % props.items.length;
        return true;
    }
    if (event.key === 'ArrowDown') {
        selectedIndex.value = (selectedIndex.value + 1) % props.items.length;
        return true;
    }
    if (event.key === 'Enter') {
        selectItem(selectedIndex.value);
        return true;
    }
    return false;
};

const selectItem = (index: number) => {
    const item = props.items[index];
    if (item) props.command({ id: item.id, label: item.name });
};

defineExpose({ onKeyDown });
</script>

<template>
    <div
        class="min-w-[180px] overflow-hidden rounded-md border border-slate-200 bg-white p-1 shadow-md dark:border-slate-800 dark:bg-slate-900"
    >
        <div v-if="items.length" class="grid gap-0.5">
            <button
                v-for="(item, index) in items"
                :key="item.id"
                class="flex h-9 w-full items-center gap-2.5 rounded-md px-2 text-left transition-colors"
                :class="
                    index === selectedIndex ? FLAT_ROW_SELECTED : FLAT_ROW_HOVER
                "
                @click="selectItem(index)"
            >
                <div
                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-projector-primary-50 text-[9px] font-black text-projector-primary-600 dark:bg-projector-primary-950/40 dark:text-projector-primary-400"
                >
                    {{ item.first_name?.[0] }}{{ item.last_name?.[0] }}
                </div>
                <span
                    class="truncate text-[13px] font-medium text-slate-900 dark:text-slate-100"
                    >{{ item.name }}</span
                >
            </button>
        </div>
        <div v-else class="px-2 py-2 text-[13px] text-slate-400">
            No users found
        </div>
    </div>
</template>

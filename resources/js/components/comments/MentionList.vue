<script setup lang="ts">
import { ref, watch } from 'vue';

const props = defineProps<{
    items: { id: number; name: string; first_name: string; last_name: string }[];
    command: (item: { id: number; name: string }) => void;
}>();

const selectedIndex = ref(0);

watch(() => props.items, () => { selectedIndex.value = 0; });

const onKeyDown = (event: KeyboardEvent) => {
    if (event.key === 'ArrowUp') {
        selectedIndex.value = (selectedIndex.value - 1 + props.items.length) % props.items.length;
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
    <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-lg overflow-hidden min-w-[180px]">
        <div v-if="items.length" class="py-1">
            <button
                v-for="(item, index) in items"
                :key="item.id"
                class="w-full flex items-center gap-2.5 px-3 py-2 text-left transition-colors"
                :class="index === selectedIndex ? 'bg-projector-primary-50 dark:bg-projector-primary-950' : 'hover:bg-slate-50 dark:hover:bg-slate-700'"
                @click="selectItem(index)"
            >
                <div class="h-6 w-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-900 flex items-center justify-center text-[9px] font-black text-projector-primary-600 dark:text-projector-primary-400 shrink-0">
                    {{ item.first_name?.[0] }}{{ item.last_name?.[0] }}
                </div>
                <span class="text-sm font-medium text-slate-900 dark:text-slate-100 truncate">{{ item.name }}</span>
            </button>
        </div>
        <div v-else class="px-3 py-2 text-sm text-slate-400">No users found</div>
    </div>
</template>

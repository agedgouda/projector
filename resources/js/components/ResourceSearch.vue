<script setup lang="ts" generic="T extends Record<string, any>">
import { ref, watch } from 'vue';
import { Search, X } from 'lucide-vue-next';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    items: T[];
    searchKeys: string[];
    placeholder?: string;
}>();

const emit = defineEmits<{
    (e: 'update:filtered', items: T[]): void;
    (e: 'update:expand', ids: (string | number)[]): void; // New emit
}>();

const query = ref('');

const performSearch = () => {
    if (!query.value) {
        emit('update:filtered', props.items);
        emit('update:expand', []);
        return;
    }

    const s = query.value.toLowerCase();
    const expandIds: (string | number)[] = [];

    const filtered = props.items.filter((item) => {
        return props.searchKeys.some((key) => {
            const value = item[key];

            // 1. Search top-level string properties (e.g., Company Name)
            if (typeof value === 'string' && value.toLowerCase().includes(s)) {
                return true;
            }

            // 2. Search nested arrays (e.g., Projects or Users)
            if (Array.isArray(value)) {
                const hasMatch = value.some((nestedItem: any) => {
                    // We only want to search string fields inside the nested objects
                    return Object.values(nestedItem).some(val =>
                        typeof val === 'string' && val.toLowerCase().includes(s)
                    );
                });

                if (hasMatch) {
                    expandIds.push(item.id);
                    return true;
                }
            }
            return false;
        });
    });

    emit('update:filtered', filtered);
    emit('update:expand', expandIds);
};

const clear = () => {
    query.value = '';
    performSearch();
};

watch(() => props.items, performSearch, { deep: true });
watch(query, performSearch);
</script>

<template>
    <div class="relative w-full">
        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <Search class="h-4 w-4 text-gray-400" />
        </div>
        <Input
            v-model="query"
            type="text"
            :placeholder="placeholder || 'Search...'"
            class="pl-10 pr-10 h-10 w-full bg-white dark:bg-zinc-900 border-gray-200 dark:border-zinc-800 rounded-xl focus:ring-indigo-500 shadow-sm transition-all"
        />
        <button v-if="query" @click="clear" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
            <X class="h-4 w-4" />
        </button>
    </div>
</template>

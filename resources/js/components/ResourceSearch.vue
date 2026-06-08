<script setup lang="ts" generic="T extends Record<string, any>">
import { ref, watch } from 'vue';
import { Search, X } from 'lucide-vue-next';
import { Input } from '@/components/ui/input';
import { FLAT_SEARCH_ICON, FLAT_SEARCH_INPUT } from '@/lib/flat-ui';

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
    <div class="relative w-full md:w-80 lg:w-96 group">
        <Search :class="FLAT_SEARCH_ICON" />
        <Input
            v-model="query"
            type="text"
            :placeholder="placeholder || 'Search...'"
            :class="[FLAT_SEARCH_INPUT, 'pr-7']"
        />
        <button v-if="query" @click="clear" class="absolute right-1 top-1/2 -translate-y-1/2 p-0.5 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-full transition-colors">
            <X class="w-3 h-3 text-slate-500" />
        </button>
    </div>
</template>

<script setup lang="ts">
import { Trash2 } from 'lucide-vue-next';

defineProps<{
    title: string;
    description?: string;
    pillText?: string;
    showDelete?: boolean; // Add this prop
}>();

defineEmits(['delete', 'click']);
</script>

<template>
    <div class="bg-white dark:bg-gray-800 p-4 rounded-xl border border-gray-200 dark:border-gray-700 flex justify-between items-center shadow-sm hover:border-indigo-300 dark:hover:border-indigo-800 transition-colors group">
        <div class="min-w-0 flex-1 mr-4" @click="$emit('click')">
            <div class="flex items-center gap-2 flex-wrap">
                <slot name="icon"></slot>

                <h4 class="font-bold text-gray-900 dark:text-white truncate">
                    {{ title }}
                </h4>

                <span v-if="pillText" class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                    {{ pillText }}
                </span>
            </div>

            <p v-if="description" class="text-xs text-gray-500 mt-1 line-clamp-1">
                {{ description }}
            </p>

            <slot />
        </div>

        <div class="flex items-center gap-4 shrink-0">
            <slot name="actions"></slot>

            <button
                v-if="showDelete !== false"
                @click.stop="$emit('delete')"
                class="text-gray-300 hover:text-red-500 transition-colors p-1"
            >
                <Trash2 class="h-5 w-5" />
            </button>
        </div>
    </div>
</template>

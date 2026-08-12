<script setup lang="ts">
import { Trash2 } from 'lucide-vue-next';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';

const props = defineProps<{
    title: string;
    description?: string;
    pillText?: string;
    showDelete?: boolean; // Add this prop
    // Position within its list, for an alternating-stripe background — matches
    // OrgUserTable.vue's rule. Omitted by callers that don't want striping.
    rowIndex?: number;
    // Opts out of the row hover background — for callers that decided against it
    // (Roles). Other callers keep the hover by default.
    noHover?: boolean;
    // Keeps the delete button visible at all times instead of only on row hover —
    // same opt-in set as noHover above.
    alwaysShowActions?: boolean;
}>();

defineEmits(['delete', 'click']);
</script>

<template>
    <div
        :class="[
            'group flex items-center justify-between gap-3 h-12 px-2 rounded-md transition-colors',
            props.noHover ? '' : FLAT_ROW_HOVER,
            rowIndex !== undefined && rowIndex % 2 === 1 ? 'bg-projector-primary-100/70 dark:bg-projector-primary-950/25' : '',
        ]"
    >
        <div class="min-w-0 flex-1 flex items-center gap-2.5" @click="$emit('click')">
            <slot name="icon"></slot>

            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <h4 class="text-[13px] font-semibold text-slate-900 dark:text-slate-100 truncate">
                        {{ title }}
                    </h4>

                    <span v-if="pillText" class="inline-flex items-center px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-widest bg-projector-primary-100 text-projector-primary-700 dark:bg-projector-primary-900/40 dark:text-projector-primary-300">
                        {{ pillText }}
                    </span>
                </div>

                <p v-if="description" class="text-[11px] text-slate-400 truncate">
                    {{ description }}
                </p>
            </div>

            <slot />
        </div>

        <div class="flex items-center gap-3 shrink-0">
            <slot name="actions"></slot>

            <button
                v-if="showDelete !== false"
                @click.stop="$emit('delete')"
                :class="[
                    'h-7 w-7 flex items-center justify-center rounded-md text-slate-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 transition-colors shrink-0',
                    alwaysShowActions ? '' : 'opacity-0 group-hover:opacity-100',
                ]"
            >
                <Trash2 class="w-3.5 h-3.5" />
            </button>
        </div>
    </div>
</template>

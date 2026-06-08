<script setup lang="ts">
import { computed, type Component } from 'vue';

const props = withDefaults(defineProps<{
    src?: string | null;
    alt?: string;
    icon: Component;
    size?: 'sm' | 'md' | 'lg';
    tone?: 'muted' | 'primary';
}>(), {
    src: null,
    alt: '',
    size: 'md',
    tone: 'muted',
});

const sizeClasses: Record<string, string> = {
    sm: 'w-6 h-6 rounded-md',
    md: 'w-8 h-8 rounded-md',
    lg: 'w-10 h-10 rounded-lg',
};

const iconSizeClasses: Record<string, string> = {
    sm: 'w-3.5 h-3.5',
    md: 'w-4 h-4',
    lg: 'w-5 h-5',
};

const toneClasses: Record<string, string> = {
    muted: 'bg-slate-100 dark:bg-slate-800 text-slate-400',
    primary: 'bg-projector-primary-50 dark:bg-projector-primary-900/50 text-projector-primary-400',
};

const tileClasses = computed(() => [
    'flex items-center justify-center overflow-hidden shrink-0',
    sizeClasses[props.size],
    toneClasses[props.tone],
]);

const iconClasses = computed(() => iconSizeClasses[props.size]);
</script>

<template>
    <div :class="tileClasses">
        <img v-if="src" :src="src" :alt="alt" class="size-full object-contain" />
        <component :is="icon" v-else :class="iconClasses" />
    </div>
</template>

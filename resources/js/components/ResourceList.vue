<script setup lang="ts" generic="T">
defineProps<{
    items: T[];
}>();
</script>

<template>
    <TransitionGroup
        tag="div"
        class="space-y-2 relative"
        move-class="transition duration-300 ease-in-out"
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="opacity-0 translate-y-4"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition duration-200 ease-in absolute w-full"
        leave-from-class="opacity-100 translate-y-0"
        leave-to-class="opacity-0 translate-y-4"
    >
        <div v-for="(item, index) in items" :key="index">
            <slot :item="item" :index="index"></slot>
        </div>
    </TransitionGroup>
</template>

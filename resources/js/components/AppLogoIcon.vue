<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <svg id="Layer_1" xmlns="http://www.w3.org/2000/svg" width="500" height="500" version="1.1" viewBox="0 0 500 500"
        :class="className"
        v-bind="$attrs"
    >
        <circle fill="#1c1210" cx="249.4" cy="249.7" r="239.5"/>
        <g>
            <path fill="#e84924" d="M303.5,335.5h-74.4c-13.6,0-24.6-11-24.6-24.6s11-24.6,24.6-24.6h74.4c40.1,0,72.7-32.6,72.7-72.7s-32.6-72.7-72.7-72.7h-169.6c-13.6,0-24.6-11-24.6-24.6s11-24.6,24.6-24.6h169.6c67.2,0,121.9,54.7,121.9,121.9s-54.7,121.9-121.9,121.9Z"/>
            <path fill="#fff" d="M334.5,208.3l-9.9-5.7-23.6-13.6-34.9-20.2c-4-2.3-9,.6-9,5.2v14.9h-71.2c-34.6,0-62.8,28.1-62.8,62.8v144.7c0,13.6,11,24.6,24.6,24.6s24.6-11,24.6-24.6v-144.7c0-7.5,6.1-13.6,13.6-13.6h71.2v14.9c0,4.6,5,7.6,9,5.2l34.9-20.2,23.6-13.6,9.9-5.7c4-2.3,4-8.1,0-10.4Z"/>
        </g>
    </svg>
</template>

<script setup lang="ts">
import type { CalendarGridBodyProps } from "reka-ui"
import { CalendarGridBody } from "reka-ui"

const props = defineProps<CalendarGridBodyProps>()
</script>

<template>
  <CalendarGridBody v-bind="props">
    <slot />
  </CalendarGridBody>
</template>

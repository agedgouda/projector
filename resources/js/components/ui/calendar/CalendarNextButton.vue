<script setup lang="ts">
import type { CalendarNextProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { ChevronRight } from "lucide-vue-next"
import { CalendarNext, useForwardProps } from "reka-ui"
import { computed } from "vue"
import { buttonVariants } from "@/components/ui/button"
import { cn } from "@/lib/utils"

const props = defineProps<CalendarNextProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props
  return delegated
})

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <CalendarNext
    :class="cn(
      buttonVariants({ variant: 'outline', size: 'icon-sm' }),
      'absolute right-1 bg-transparent p-0 opacity-60 hover:opacity-100',
      props.class,
    )"
    v-bind="forwardedProps"
  >
    <slot>
      <ChevronRight class="h-4 w-4" />
    </slot>
  </CalendarNext>
</template>

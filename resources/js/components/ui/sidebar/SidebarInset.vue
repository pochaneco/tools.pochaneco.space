<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <main
    data-slot="sidebar-inset"
    :class="cn(
      'bg-background relative flex w-full h-svh flex-col',
      'md:peer-data-[variant=inset]:p-2 md:peer-data-[variant=inset]:rounded-xl md:peer-data-[variant=inset]:shadow-sm',
      props.class,
    )"
  >
    <slot />
  </main>
</template>

<script setup lang="ts">
defineOptions({
  inheritAttrs: false,
})

defineProps<{
  text: string
  wrapperClass?: string
}>()
</script>

<template>
  <v-tooltip :text="text" location="top">
    <template #activator="{ props }">
      <span :class="['tooltip-icon-button', wrapperClass]" v-bind="props">
        <v-btn v-bind="$attrs" />
      </span>
    </template>
  </v-tooltip>
</template>

<style scoped>
.tooltip-icon-button {
  display: inline-flex;
}
</style>

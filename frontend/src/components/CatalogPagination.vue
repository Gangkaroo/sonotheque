<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  modelValue: number
  length: number
}>()

defineEmits<{
  'update:modelValue': [value: number]
}>()

const totalVisible = computed(() => Math.min(7, Math.max(1, props.length)))
</script>

<template>
  <div v-if="length > 1" class="catalog-pagination">
    <v-pagination
      density="comfortable"
      :length="length"
      :model-value="modelValue"
      show-first-last-page
      :total-visible="totalVisible"
      @update:model-value="$emit('update:modelValue', $event)"
    />
  </div>
</template>

<style scoped>
.catalog-pagination {
  display: flex;
  justify-content: center;
  max-width: 100%;
}

.catalog-pagination :deep(.v-pagination__list) {
  flex-wrap: wrap;
  justify-content: center;
}
</style>

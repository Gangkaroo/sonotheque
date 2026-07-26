<script setup lang="ts">
import DOMPurify from 'dompurify'
import { computed } from 'vue'

const props = defineProps<{
  html: string
}>()

const sanitizedHtml = computed(() => DOMPurify.sanitize(props.html, {
  ALLOWED_ATTR: ['href', 'rel', 'target'],
  ALLOWED_TAGS: ['a', 'blockquote', 'br', 'em', 'li', 'ol', 'p', 's', 'strong', 'ul'],
}))
</script>

<template>
  <!-- The HTML is sanitized locally and has already passed the backend sanitizer. -->
  <!-- eslint-disable-next-line vue/no-v-html -->
  <div class="rich-text-content" v-html="sanitizedHtml" />
</template>

<style scoped>
.rich-text-content {
  overflow-wrap: anywhere;
}

.rich-text-content :deep(p),
.rich-text-content :deep(blockquote),
.rich-text-content :deep(ol),
.rich-text-content :deep(ul) {
  margin-bottom: 0.5rem;
}

.rich-text-content :deep(:last-child) {
  margin-bottom: 0;
}

.rich-text-content :deep(blockquote) {
  border-inline-start: 3px solid rgba(var(--v-theme-primary), 0.45);
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  padding-inline-start: 0.75rem;
}

.rich-text-content :deep(ol),
.rich-text-content :deep(ul) {
  padding-inline-start: 1.5rem;
}

.rich-text-content :deep(a) {
  color: rgb(var(--v-theme-primary));
}
</style>

<script setup lang="ts">
import DOMPurify from 'dompurify'
import { marked } from 'marked'
import { computed } from 'vue'

const props = defineProps<{
  content: string
}>()

const renderedHtml = computed(() => {
  const parsed = marked.parse(props.content, {
    async: false,
    breaks: true,
    gfm: true,
  })
  const sanitized = DOMPurify.sanitize(parsed, {
    ALLOWED_TAGS: [
      'a',
      'blockquote',
      'br',
      'code',
      'em',
      'h1',
      'h2',
      'h3',
      'h4',
      'li',
      'ol',
      'p',
      'pre',
      'strong',
      'ul',
    ],
    ALLOWED_ATTR: ['href', 'title'],
  })
  const document = new DOMParser().parseFromString(sanitized, 'text/html')
  document.querySelectorAll('a').forEach((link) => {
    link.target = '_blank'
    link.rel = 'noopener noreferrer'
  })

  return document.body.innerHTML
})
</script>

<template>
  <!-- Model-generated Markdown is parsed locally and sanitized before display. -->
  <!-- eslint-disable-next-line vue/no-v-html -->
  <div class="assistant-markdown" v-html="renderedHtml" />
</template>

<style scoped>
.assistant-markdown {
  overflow-wrap: anywhere;
}

.assistant-markdown :deep(p),
.assistant-markdown :deep(ul),
.assistant-markdown :deep(ol),
.assistant-markdown :deep(blockquote),
.assistant-markdown :deep(pre) {
  margin: 0 0 0.65rem;
}

.assistant-markdown :deep(:last-child) {
  margin-bottom: 0;
}

.assistant-markdown :deep(ul),
.assistant-markdown :deep(ol) {
  padding-inline-start: 1.35rem;
}

.assistant-markdown :deep(h1),
.assistant-markdown :deep(h2),
.assistant-markdown :deep(h3),
.assistant-markdown :deep(h4) {
  margin: 0.75rem 0 0.4rem;
  font-size: 1rem;
  font-weight: 600;
  line-height: 1.4;
}

.assistant-markdown :deep(blockquote) {
  padding-inline-start: 0.75rem;
  border-inline-start: 3px solid rgba(var(--v-theme-primary), 0.55);
  color: rgba(var(--v-theme-on-surface), 0.78);
}

.assistant-markdown :deep(code) {
  padding: 0.08rem 0.3rem;
  border-radius: 4px;
  background: rgba(var(--v-theme-on-surface), 0.08);
  font-size: 0.9em;
}

.assistant-markdown :deep(pre) {
  overflow-x: auto;
  padding: 0.75rem;
  border-radius: 6px;
  background: rgba(var(--v-theme-on-surface), 0.08);
}

.assistant-markdown :deep(pre code) {
  padding: 0;
  background: transparent;
}

.assistant-markdown :deep(a) {
  color: rgb(var(--v-theme-primary));
}
</style>

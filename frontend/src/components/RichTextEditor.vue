<script setup lang="ts">
import StarterKit from '@tiptap/starter-kit'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import TooltipIconButton from '@/components/TooltipIconButton.vue'

const props = withDefaults(defineProps<{
  disabled?: boolean
  label?: string
  maxLength?: number
  modelValue: string
}>(), {
  disabled: false,
  label: undefined,
  maxLength: undefined,
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const { t } = useI18n()
const linkDialog = ref(false)
const linkError = ref<string | null>(null)
const linkUrl = ref('')
const editor = useEditor({
  content: props.modelValue,
  editable: !props.disabled,
  extensions: [
    StarterKit.configure({
      code: false,
      codeBlock: false,
      heading: false,
      horizontalRule: false,
      link: {
        HTMLAttributes: {
          rel: 'noopener noreferrer',
          target: '_blank',
        },
        openOnClick: false,
        protocols: ['http', 'https'],
      },
      strike: false,
    }),
  ],
  onUpdate: ({ editor: currentEditor }) => {
    emit('update:modelValue', currentEditor.isEmpty ? '' : currentEditor.getHTML())
  },
})

watch(() => props.modelValue, (value) => {
  if (!editor.value || editor.value.getHTML() === value) return

  editor.value.commands.setContent(value, { emitUpdate: false })
})

watch(() => props.disabled, (disabled) => {
  editor.value?.setEditable(!disabled)
})

function openLinkDialog() {
  linkUrl.value = editor.value?.getAttributes('link').href ?? ''
  linkError.value = null
  linkDialog.value = true
}

function applyLink() {
  if (!editor.value) return

  const normalizedUrl = normalizeWebUrl(linkUrl.value)
  if (!normalizedUrl) {
    linkError.value = t('richText.invalidLink')

    return
  }

  editor.value
    .chain()
    .focus()
    .extendMarkRange('link')
    .setLink({
      href: normalizedUrl,
      rel: 'noopener noreferrer',
      target: '_blank',
    })
    .run()
  linkDialog.value = false
}

function removeLink() {
  editor.value?.chain().focus().extendMarkRange('link').unsetLink().run()
  linkDialog.value = false
}

function normalizeWebUrl(value: string): string | null {
  const trimmedValue = value.trim()
  if (!trimmedValue) return null

  const candidate = /^[a-z][a-z\d+.-]*:/i.test(trimmedValue)
    ? trimmedValue
    : `https://${trimmedValue}`

  try {
    const url = new URL(candidate)

    return ['http:', 'https:'].includes(url.protocol) ? url.toString() : null
  } catch {
    return null
  }
}
</script>

<template>
  <div class="rich-text-editor" :class="{ 'rich-text-editor--disabled': disabled }">
    <div v-if="label" class="rich-text-editor__label text-caption text-medium-emphasis">
      {{ label }}
    </div>
    <div v-if="editor" class="rich-text-editor__toolbar">
      <TooltipIconButton
        :aria-label="t('richText.bold')"
        color="primary"
        density="comfortable"
        :disabled="disabled"
        icon="mdi-format-bold"
        size="small"
        :text="t('richText.bold')"
        :variant="editor.isActive('bold') ? 'tonal' : 'text'"
        @click="editor.chain().focus().toggleBold().run()"
      />
      <TooltipIconButton
        :aria-label="t('richText.italic')"
        color="primary"
        density="comfortable"
        :disabled="disabled"
        icon="mdi-format-italic"
        size="small"
        :text="t('richText.italic')"
        :variant="editor.isActive('italic') ? 'tonal' : 'text'"
        @click="editor.chain().focus().toggleItalic().run()"
      />
      <v-divider class="mx-1" vertical />
      <TooltipIconButton
        :aria-label="t('richText.bulletList')"
        color="primary"
        density="comfortable"
        :disabled="disabled"
        icon="mdi-format-list-bulleted"
        size="small"
        :text="t('richText.bulletList')"
        :variant="editor.isActive('bulletList') ? 'tonal' : 'text'"
        @click="editor.chain().focus().toggleBulletList().run()"
      />
      <TooltipIconButton
        :aria-label="t('richText.orderedList')"
        color="primary"
        density="comfortable"
        :disabled="disabled"
        icon="mdi-format-list-numbered"
        size="small"
        :text="t('richText.orderedList')"
        :variant="editor.isActive('orderedList') ? 'tonal' : 'text'"
        @click="editor.chain().focus().toggleOrderedList().run()"
      />
      <TooltipIconButton
        :aria-label="t('richText.blockquote')"
        color="primary"
        density="comfortable"
        :disabled="disabled"
        icon="mdi-format-quote-close"
        size="small"
        :text="t('richText.blockquote')"
        :variant="editor.isActive('blockquote') ? 'tonal' : 'text'"
        @click="editor.chain().focus().toggleBlockquote().run()"
      />
      <TooltipIconButton
        :aria-label="t('richText.addLink')"
        color="primary"
        density="comfortable"
        :disabled="disabled"
        icon="mdi-link-variant"
        size="small"
        :text="t('richText.addLink')"
        :variant="editor.isActive('link') ? 'tonal' : 'text'"
        @click="openLinkDialog"
      />
      <v-spacer />
      <TooltipIconButton
        :aria-label="t('richText.undo')"
        color="primary"
        density="comfortable"
        :disabled="disabled || !editor.can().chain().focus().undo().run()"
        icon="mdi-undo"
        size="small"
        :text="t('richText.undo')"
        variant="text"
        @click="editor.chain().focus().undo().run()"
      />
      <TooltipIconButton
        :aria-label="t('richText.redo')"
        color="primary"
        density="comfortable"
        :disabled="disabled || !editor.can().chain().focus().redo().run()"
        icon="mdi-redo"
        size="small"
        :text="t('richText.redo')"
        variant="text"
        @click="editor.chain().focus().redo().run()"
      />
    </div>
    <EditorContent v-if="editor" :editor="editor" class="rich-text-editor__content" />
    <div v-if="maxLength" class="rich-text-editor__counter text-caption text-medium-emphasis">
      {{ modelValue.length }} / {{ maxLength }}
    </div>
  </div>

  <v-dialog v-model="linkDialog" max-width="520">
    <v-card prepend-icon="mdi-link-variant" :title="t('richText.addLink')">
      <v-card-text>
        <v-text-field
          v-model="linkUrl"
          autofocus
          clearable
          :error-messages="linkError ? [linkError] : []"
          :label="t('richText.linkUrl')"
          placeholder="https://example.com"
          @keyup.enter="applyLink"
        />
      </v-card-text>
      <v-card-actions>
        <v-btn
          v-if="editor?.isActive('link')"
          color="error"
          variant="text"
          @click="removeLink"
        >
          {{ t('richText.removeLink') }}
        </v-btn>
        <v-spacer />
        <v-btn @click="linkDialog = false">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="primary" variant="flat" @click="applyLink">
          {{ t('richText.applyLink') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.rich-text-editor {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  overflow: hidden;
}

.rich-text-editor:focus-within {
  border-color: rgb(var(--v-theme-primary));
  border-width: 2px;
}

.rich-text-editor--disabled {
  opacity: var(--v-disabled-opacity);
}

.rich-text-editor__label {
  padding: 8px 12px 0;
}

.rich-text-editor__toolbar {
  align-items: center;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  display: flex;
  flex-wrap: wrap;
  min-height: 42px;
  padding: 2px 6px;
}

.rich-text-editor__content {
  min-height: 150px;
  padding: 12px;
}

.rich-text-editor__content :deep(.tiptap) {
  min-height: 126px;
  outline: none;
}

.rich-text-editor__content :deep(.tiptap p),
.rich-text-editor__content :deep(.tiptap blockquote),
.rich-text-editor__content :deep(.tiptap ol),
.rich-text-editor__content :deep(.tiptap ul) {
  margin-bottom: 0.5rem;
}

.rich-text-editor__content :deep(.tiptap :last-child) {
  margin-bottom: 0;
}

.rich-text-editor__content :deep(.tiptap blockquote) {
  border-inline-start: 3px solid rgba(var(--v-theme-primary), 0.45);
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  padding-inline-start: 0.75rem;
}

.rich-text-editor__content :deep(.tiptap ol),
.rich-text-editor__content :deep(.tiptap ul) {
  padding-inline-start: 1.5rem;
}

.rich-text-editor__counter {
  padding: 0 12px 8px;
  text-align: end;
}
</style>

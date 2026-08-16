<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import AssistantMarkdown from '@/components/AssistantMarkdown.vue'
import PageHeader from '@/components/PageHeader.vue'
import { useCollectionAssistantStore } from '@/stores/collectionAssistant'
import { useCollectionAssistantSettingsStore } from '@/stores/collectionAssistantSettings'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'
import { useLibraryRootsStore } from '@/stores/libraryRoots'
import { usePlayerStore } from '@/stores/player'
import type { CollectionAssistantMessage } from '@/stores/collectionAssistant'

const { locale, t } = useI18n()
const assistant = useCollectionAssistantStore()
const assistantSettings = useCollectionAssistantSettingsStore()
const libraryRootScope = useLibraryRootScopeStore()
const libraryRoots = useLibraryRootsStore()
const player = usePlayerStore()
const question = ref('')
const clearDialog = ref(false)
const settingsReady = ref(false)
const conversationElement = ref<HTMLElement | null>(null)

const scopeKey = computed(() => libraryRootScope.scopeKey)
const messages = computed(() => assistant.messagesFor(scopeKey.value))
const submitting = computed(() => assistant.isSubmitting(scopeKey.value))
const requestError = computed(() => assistant.errorFor(scopeKey.value))
const selectedRoot = computed(() => libraryRoots.roots.find(
  (root) => root.id === libraryRootScope.selectedRootId,
))
const scopeLabel = computed(() => selectedRoot.value?.name ?? t('libraryScope.allRoots'))
const isConfigured = computed(() => (
  assistantSettings.settings.enabled && Boolean(assistantSettings.settings.model)
))
const suggestedQuestions = computed(() => [
  t('collectionAssistant.suggestions.summary'),
  t('collectionAssistant.suggestions.artistAlbums'),
  t('collectionAssistant.suggestions.topPlayed'),
  t('collectionAssistant.suggestions.unplayed'),
  t('collectionAssistant.suggestions.similar'),
])
const errorMessage = computed(() => {
  if (!requestError.value) return null
  const key = `collectionAssistant.errors.${requestError.value.errorCode ?? 'default'}`

  return t(key, {}, { default: requestError.value.message })
})

onMounted(async () => {
  await assistantSettings.load()
  settingsReady.value = true
})

watch(
  () => messages.value.length,
  async () => {
    await nextTick()
    conversationElement.value?.lastElementChild?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
  },
)

async function submit() {
  const value = question.value.trim()
  if (!value || submitting.value) return

  question.value = ''
  try {
    await assistant.ask(
      value,
      scopeKey.value,
      libraryRootScope.selectedRootId,
      locale.value === 'de' ? 'de' : 'en',
    )
  } catch {
    // The store keeps the error context beside the conversation.
  }
}

function askSuggested(value: string) {
  question.value = value
  void submit()
}

function clearConversation() {
  assistant.clear(scopeKey.value)
  clearDialog.value = false
}

function confirmAction(message: CollectionAssistantMessage) {
  const action = message.action
  const firstTrack = action?.tracks[0]
  if (!action || !firstTrack || action.state) return

  if (action.mode === 'play') {
    player.playTrack(firstTrack, action.tracks, 'track-list')
  } else {
    player.queueTracks(action.tracks, 'track-list')
  }
  assistant.setActionState(scopeKey.value, message.id, 'confirmed')
}

function dismissAction(message: CollectionAssistantMessage) {
  if (!message.action || message.action.state) return
  assistant.setActionState(scopeKey.value, message.id, 'dismissed')
}
</script>

<template>
  <PageHeader
    :title="t('collectionAssistant.title')"
    :description="t('collectionAssistant.description')"
    icon="mdi-message-processing-outline"
  />

  <div class="assistant-toolbar mb-4">
    <v-chip color="primary" prepend-icon="mdi-harddisk" variant="tonal">
      {{ t('collectionAssistant.scope', { scope: scopeLabel }) }}
    </v-chip>
    <v-btn
      v-if="messages.length"
      prepend-icon="mdi-delete-outline"
      size="small"
      variant="text"
      @click="clearDialog = true"
    >
      {{ t('collectionAssistant.clear') }}
    </v-btn>
  </div>

  <v-alert
    v-if="settingsReady && !isConfigured"
    class="mb-4"
    type="warning"
    variant="tonal"
  >
    <div class="d-flex flex-wrap align-center justify-space-between ga-3">
      <span>{{ t('collectionAssistant.notConfigured') }}</span>
      <v-btn
        color="warning"
        prepend-icon="mdi-cog-outline"
        size="small"
        to="/settings?tab=assistant"
        variant="outlined"
      >
        {{ t('collectionAssistant.openSettings') }}
      </v-btn>
    </div>
  </v-alert>

  <v-card class="assistant-card" variant="outlined">
    <v-card-text ref="conversationElement" class="assistant-conversation pa-4 pa-sm-6">
      <div v-if="messages.length === 0" class="assistant-empty text-center mx-auto">
        <v-avatar color="primary" size="64" variant="tonal">
          <v-icon icon="mdi-bookshelf" size="34" />
        </v-avatar>
        <h2 class="text-h6 mt-4">{{ t('collectionAssistant.emptyTitle') }}</h2>
        <p class="text-body-2 text-medium-emphasis mt-2">
          {{ t('collectionAssistant.emptyDescription') }}
        </p>
        <div class="d-flex flex-wrap justify-center ga-2 mt-5">
          <v-btn
            v-for="suggestion in suggestedQuestions"
            :key="suggestion"
            color="primary"
            size="small"
            variant="tonal"
            @click="askSuggested(suggestion)"
          >
            {{ suggestion }}
          </v-btn>
        </div>
      </div>

      <div
        v-for="message in messages"
        :key="message.id"
        class="assistant-message"
        :class="`assistant-message--${message.role}`"
      >
        <v-avatar
          :color="message.role === 'assistant' ? 'primary' : undefined"
          size="32"
          :variant="message.role === 'assistant' ? 'tonal' : 'flat'"
        >
          <v-icon :icon="message.role === 'assistant' ? 'mdi-waveform' : 'mdi-account-outline'" size="18" />
        </v-avatar>
        <div class="assistant-message-body">
          <v-sheet
            class="assistant-bubble pa-3 px-sm-4"
            :color="message.role === 'user' ? 'primary' : 'surface-variant'"
            rounded="lg"
          >
            <AssistantMarkdown
              v-if="message.role === 'assistant'"
              class="text-body-1"
              :content="message.content"
            />
            <div v-else class="assistant-message-text text-body-1">{{ message.content }}</div>
          </v-sheet>
          <div v-if="message.references.length" class="assistant-references mt-2">
            <v-btn
              v-for="reference in message.references"
              :key="reference.path"
              color="primary"
              :to="reference.path"
              size="small"
              variant="text"
            >
              {{ reference.label }}
              <v-icon end icon="mdi-arrow-right" />
            </v-btn>
          </div>
          <v-card
            v-if="message.role === 'assistant' && message.action"
            class="assistant-action mt-3"
            color="surface"
            variant="outlined"
          >
            <v-card-title class="d-flex align-center ga-2 text-subtitle-1 pb-1">
              <v-icon color="primary" icon="mdi-playlist-play" />
              {{ t('collectionAssistant.action.title') }}
            </v-card-title>
            <v-card-subtitle class="text-wrap">
              {{ t(`collectionAssistant.action.${message.action.mode}Description`) }}
            </v-card-subtitle>
            <v-card-text class="pt-3 pb-2">
              <div class="d-flex flex-wrap ga-2 mb-3">
                <v-chip prepend-icon="mdi-music-note" size="small" variant="tonal">
                  {{ t('collectionAssistant.action.trackCount', { count: message.action.tracks.length }) }}
                </v-chip>
                <v-chip prepend-icon="mdi-harddisk" size="small" variant="tonal">
                  {{ message.action.scope.id === null
                    ? t('libraryScope.allRoots')
                    : message.action.scope.name }}
                </v-chip>
              </div>
              <v-list class="assistant-action-list" density="compact" lines="two">
                <v-list-item
                  v-for="(track, index) in message.action.tracks"
                  :key="track.id"
                  :subtitle="[track.artists.map((artist) => artist.name).join(', '), track.album?.title].filter(Boolean).join(' · ')"
                  :title="track.title"
                >
                  <template #prepend>
                    <span class="assistant-action-position text-caption text-medium-emphasis">
                      {{ index + 1 }}
                    </span>
                  </template>
                </v-list-item>
              </v-list>
              <v-alert
                v-if="message.action.state"
                class="mt-3"
                density="compact"
                :type="message.action.state === 'confirmed' ? 'success' : 'info'"
                variant="tonal"
              >
                {{ t(`collectionAssistant.action.${message.action.state}`) }}
              </v-alert>
            </v-card-text>
            <v-card-actions v-if="!message.action.state" class="pt-0">
              <v-spacer />
              <v-btn variant="text" @click="dismissAction(message)">
                {{ t('collectionAssistant.action.dismiss') }}
              </v-btn>
              <v-btn
                color="primary"
                :prepend-icon="message.action.mode === 'play' ? 'mdi-play' : 'mdi-playlist-plus'"
                variant="tonal"
                @click="confirmAction(message)"
              >
                {{ t(`collectionAssistant.action.${message.action.mode}Confirm`) }}
              </v-btn>
            </v-card-actions>
          </v-card>
        </div>
      </div>

      <div v-if="submitting" class="assistant-message assistant-message--assistant">
        <v-avatar color="primary" size="32" variant="tonal">
          <v-icon icon="mdi-waveform" size="18" />
        </v-avatar>
        <v-sheet class="assistant-thinking px-4 py-3" color="surface-variant" rounded="lg">
          <v-progress-circular color="primary" indeterminate size="18" width="2" />
          <span class="text-body-2 text-medium-emphasis">{{ t('collectionAssistant.thinking') }}</span>
        </v-sheet>
      </div>
    </v-card-text>

    <v-divider />

    <v-card-text class="assistant-composer pa-3 pa-sm-4">
      <v-alert v-if="errorMessage" class="mb-3" density="compact" type="error" variant="tonal">
        {{ errorMessage }}
      </v-alert>
      <div class="assistant-input-row">
        <v-textarea
          v-model="question"
          auto-grow
          autofocus
          hide-details
          :label="t('collectionAssistant.questionLabel')"
          :max-rows="5"
          :placeholder="t('collectionAssistant.questionPlaceholder')"
          rows="1"
          variant="outlined"
          @keydown.enter.exact.prevent="submit"
        />
        <v-btn
          :aria-label="t('collectionAssistant.send')"
          color="primary"
          :disabled="!question.trim() || submitting || !settingsReady || !isConfigured"
          icon="mdi-send"
          :loading="submitting"
          @click="submit"
        />
      </div>
      <p class="text-caption text-medium-emphasis mt-2 mb-0">
        {{ t('collectionAssistant.inputHint') }}
      </p>
    </v-card-text>
  </v-card>

  <v-dialog v-model="clearDialog" max-width="440">
    <v-card>
      <v-card-title>{{ t('collectionAssistant.clearTitle') }}</v-card-title>
      <v-card-text>{{ t('collectionAssistant.clearDescription') }}</v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" @click="clearDialog = false">{{ t('collectionAssistant.cancel') }}</v-btn>
        <v-btn color="error" variant="tonal" @click="clearConversation">
          {{ t('collectionAssistant.clear') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.assistant-toolbar,
.assistant-input-row,
.assistant-message,
.assistant-thinking {
  display: flex;
  align-items: center;
}

.assistant-toolbar {
  justify-content: space-between;
  gap: 12px;
}

.assistant-card {
  max-width: 1000px;
  margin-inline: auto;
  border-color: rgba(var(--v-theme-on-surface), 0.08) !important;
}

.assistant-conversation {
  min-height: 420px;
  max-height: min(62vh, 760px);
  overflow-y: auto;
}

.assistant-empty {
  max-width: 680px;
  padding-block: 72px;
}

.assistant-message {
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 20px;
}

.assistant-message--user {
  flex-direction: row-reverse;
}

.assistant-message-body {
  max-width: min(78%, 740px);
}

.assistant-message--user .assistant-bubble {
  color: rgb(var(--v-theme-on-primary));
}

.assistant-message-text {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.assistant-references {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.assistant-action {
  max-width: 620px;
}

.assistant-action-list {
  max-height: 300px;
  overflow-y: auto;
  background: transparent;
}

.assistant-action-position {
  width: 28px;
  text-align: end;
  margin-inline-end: 14px;
}

.assistant-thinking {
  gap: 10px;
}

.assistant-input-row {
  align-items: flex-end;
  gap: 12px;
}

@media (max-width: 600px) {
  .assistant-conversation {
    min-height: 360px;
    max-height: none;
  }

  .assistant-empty {
    padding-block: 40px;
  }

  .assistant-message-body {
    max-width: calc(100% - 44px);
  }
}
</style>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import type {
  PlaylistDetail,
  PlaylistSimilarityOrderPreview,
  PlaylistSimilarityOrderStatus,
} from '@/stores/playlists'
import { usePlaylistsStore } from '@/stores/playlists'

const props = defineProps<{
  playlist: PlaylistDetail
}>()

const dialog = defineModel<boolean>({ default: false })
const { t } = useI18n()
const playlists = usePlaylistsStore()

const openingItemId = ref<number | null>(null)
const status = ref<PlaylistSimilarityOrderStatus | null>(null)
const preview = ref<PlaylistSimilarityOrderPreview | null>(null)
const applied = ref(false)
const saveCopyMode = ref(false)
const copyName = ref('')
const copyFolderId = ref<number | null>(null)
const successMessage = ref('')

const openingTrackOptions = computed(() => {
  const analyzed = new Set(status.value?.analyzedItemIds ?? [])
  return props.playlist.items.map((item, index) => {
    const available = analyzed.has(item.id)
    return {
      title: `${index + 1}. ${item.track.title} · ${artistName(item.track.artists.map(artist => artist.name))}${available ? '' : ` · ${t('playlists.similarityOrderNotAnalyzed')}`}`,
      value: item.id,
      props: { disabled: !available },
    }
  })
})
const folderOptions = computed(() => [
  { title: t('playlists.noFolder'), value: null },
  ...playlists.folders.map(folder => ({ title: folder.name, value: folder.id })),
])
const proposedItems = computed(() => {
  if (!preview.value) return []

  const items = new Map(props.playlist.items.map(item => [item.id, item]))
  return preview.value.items.flatMap((proposal, index) => {
    const item = items.get(proposal.itemId)
    return item ? [{ ...proposal, index, item }] : []
  })
})
const proposedItemIds = computed(() => preview.value?.items.map(item => item.itemId) ?? [])
const proposedTrackIds = computed(() => proposedItems.value.map(item => item.item.track.id))

function artistName(names: string[]) {
  return names.length ? names.join(', ') : t('catalog.unknownArtist')
}

function percentage(value: number | null) {
  return value === null ? '--' : `${Math.round(value * 100)}%`
}

async function createPreview() {
  if (openingItemId.value === null) return

  preview.value = await playlists.previewSimilarityOrder(props.playlist.id, openingItemId.value)
  applied.value = false
  saveCopyMode.value = false
  successMessage.value = ''
}

async function applyOrder() {
  if (!preview.value) return

  const result = await playlists.applySimilarityOrder(
    props.playlist.id,
    proposedItemIds.value,
    preview.value.orderSignature,
  )
  preview.value = { ...preview.value, canUndo: result.canUndo }
  applied.value = true
  successMessage.value = t('playlists.similarityOrderApplied')
}

async function restoreOrder() {
  const result = await playlists.restoreSimilarityOrder(props.playlist.id)
  if (preview.value) preview.value = { ...preview.value, canUndo: result.canUndo }
  applied.value = false
  successMessage.value = t('playlists.similarityOrderRestored')
}

async function saveCopy() {
  const name = copyName.value.trim()
  if (!preview.value || !name) return

  const playlist = await playlists.createPlaylist({
    name,
    folderId: copyFolderId.value,
    trackIds: proposedTrackIds.value,
  })
  successMessage.value = t('playlists.similarityOrderCopySaved', { name: playlist.name })
  saveCopyMode.value = false
}

watch(dialog, async (open) => {
  if (!open) return

  openingItemId.value = null
  status.value = null
  preview.value = null
  applied.value = false
  saveCopyMode.value = false
  copyName.value = t('playlists.similarityOrderCopyName', { name: props.playlist.name })
  copyFolderId.value = props.playlist.folder?.id ?? null
  successMessage.value = ''
  playlists.similarityOrderError = null
  if (!playlists.folders.length) void playlists.loadFolders().catch(() => undefined)
  try {
    status.value = await playlists.loadSimilarityOrderStatus(props.playlist.id)
    openingItemId.value = status.value.analyzedItemIds[0] ?? null
  } catch {
    // The store exposes the actionable error inside the dialog.
  }
})
</script>

<template>
  <v-dialog v-model="dialog" max-width="820" scrollable>
    <v-card rounded="xl">
      <v-card-title class="d-flex align-center ga-2">
        <v-icon color="primary" icon="mdi-vector-polyline" />
        {{ t('playlists.similarityOrderTitle') }}
      </v-card-title>
      <v-card-subtitle>{{ playlist.name }}</v-card-subtitle>

      <v-card-text>
        <v-alert class="mb-4" density="compact" type="info" variant="tonal">
          {{ t('playlists.similarityOrderDescription') }}
        </v-alert>
        <v-alert v-if="playlists.similarityOrderError" class="mb-4" type="error" variant="tonal">
          {{ playlists.similarityOrderError }}
        </v-alert>
        <v-alert v-if="successMessage" class="mb-4" type="success" variant="tonal">
          {{ successMessage }}
        </v-alert>
        <v-alert
          v-if="status && !status.available && !playlists.similarityOrdering"
          class="mb-4"
          type="warning"
          variant="tonal"
        >
          {{ status.enabled
            ? t('playlists.similarityOrderInsufficientAnalysis', { count: status.analyzedItemIds.length })
            : t('playlists.similarityOrderDisabled') }}
        </v-alert>

        <div class="order-controls">
          <v-autocomplete
            v-model="openingItemId"
            density="compact"
            :disabled="playlists.similarityOrdering"
            hide-details="auto"
            :items="openingTrackOptions"
            :label="t('playlists.similarityOrderOpeningTrack')"
          />
          <v-btn
            color="primary"
            :disabled="openingItemId === null || !status?.available"
            :loading="playlists.similarityOrdering && !preview"
            prepend-icon="mdi-chart-timeline-variant-shimmer"
            variant="tonal"
            @click="createPreview"
          >
            {{ t('playlists.similarityOrderPreview') }}
          </v-btn>
        </div>

        <template v-if="preview">
          <div class="d-flex flex-wrap ga-2 my-4">
            <v-chip color="primary" prepend-icon="mdi-check-decagram-outline" size="small" variant="tonal">
              {{ t('playlists.similarityOrderAnalyzed', { count: preview.summary.analyzedCount }) }}
            </v-chip>
            <v-chip prepend-icon="mdi-chart-line" size="small" variant="tonal">
              {{ t('playlists.similarityOrderAverage', { value: percentage(preview.summary.optimizedAverageSimilarity) }) }}
            </v-chip>
            <v-chip v-if="preview.summary.improvement !== null" prepend-icon="mdi-trending-up" size="small" variant="tonal">
              {{ t('playlists.similarityOrderImprovement', { value: percentage(preview.summary.improvement) }) }}
            </v-chip>
          </div>

          <v-alert
            v-if="preview.summary.unanalyzedCount"
            class="mb-4"
            density="compact"
            type="warning"
            variant="tonal"
          >
            {{ t('playlists.similarityOrderUnanalyzed', { count: preview.summary.unanalyzedCount }) }}
          </v-alert>

          <v-list border density="compact" lines="two" rounded="lg">
            <v-list-item v-for="proposal in proposedItems" :key="proposal.itemId">
              <template #prepend>
                <span class="order-position text-caption text-medium-emphasis">{{ proposal.index + 1 }}</span>
              </template>
              <v-list-item-title>{{ proposal.item.track.title }}</v-list-item-title>
              <v-list-item-subtitle>
                {{ artistName(proposal.item.track.artists.map(artist => artist.name)) }}
                <template v-if="proposal.item.track.album"> · {{ proposal.item.track.album.title }}</template>
              </v-list-item-subtitle>
              <template #append>
                <v-chip
                  v-if="proposal.analyzed && proposal.similarityToPrevious !== null"
                  color="primary"
                  size="x-small"
                  variant="tonal"
                >
                  {{ percentage(proposal.similarityToPrevious) }}
                </v-chip>
                <v-chip v-else-if="!proposal.analyzed" color="warning" size="x-small" variant="tonal">
                  {{ t('playlists.similarityOrderNotAnalyzed') }}
                </v-chip>
              </template>
            </v-list-item>
          </v-list>

          <v-expand-transition>
            <div v-if="saveCopyMode" class="save-copy-controls mt-4">
              <v-text-field
                v-model="copyName"
                density="compact"
                hide-details="auto"
                :label="t('playlists.playlistName')"
              />
              <v-autocomplete
                v-model="copyFolderId"
                clearable
                density="compact"
                hide-details="auto"
                :items="folderOptions"
                :label="t('playlists.folder')"
              />
              <v-btn
                color="primary"
                :disabled="!copyName.trim()"
                :loading="playlists.saving"
                variant="tonal"
                @click="saveCopy"
              >
                {{ t('playlists.similarityOrderSaveCopyConfirm') }}
              </v-btn>
            </div>
          </v-expand-transition>
        </template>
      </v-card-text>

      <v-card-actions class="flex-wrap">
        <v-btn
          v-if="preview?.canUndo"
          :disabled="playlists.similarityOrdering"
          prepend-icon="mdi-undo"
          variant="text"
          @click="restoreOrder"
        >
          {{ t('playlists.similarityOrderUndo') }}
        </v-btn>
        <v-spacer />
        <v-btn variant="text" @click="dialog = false">{{ t('settings.close') }}</v-btn>
        <v-btn
          v-if="preview"
          prepend-icon="mdi-content-copy"
          variant="tonal"
          @click="saveCopyMode = !saveCopyMode"
        >
          {{ t('playlists.similarityOrderSaveCopy') }}
        </v-btn>
        <v-btn
          v-if="preview"
          color="primary"
          :disabled="applied"
          :loading="playlists.similarityOrdering"
          prepend-icon="mdi-check"
          variant="flat"
          @click="applyOrder"
        >
          {{ t('playlists.similarityOrderApply') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.order-controls,
.save-copy-controls {
  align-items: start;
  display: grid;
  gap: 12px;
  grid-template-columns: minmax(0, 1fr) auto;
}

.save-copy-controls {
  grid-template-columns: minmax(0, 1fr) minmax(12rem, 0.55fr) auto;
}

.order-position {
  display: inline-block;
  min-width: 2rem;
}

@media (max-width: 700px) {
  .order-controls,
  .save-copy-controls {
    grid-template-columns: 1fr;
  }
}
</style>

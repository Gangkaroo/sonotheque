<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { useLibraryRootsStore } from '@/stores/libraryRoots'
import {
  useOnlineEnrichmentSettingsStore,
  type MusicianBackfillState,
} from '@/stores/onlineEnrichmentSettings'
import { formatApproximateDuration, formatDateTime } from '@/utils/formatters'

const { locale, t } = useI18n()
const enrichment = useOnlineEnrichmentSettingsStore()
const libraryRoots = useLibraryRootsStore()
const selectedLibraryRootId = ref<number | null>(null)
const backfill = ref<MusicianBackfillState | null>(null)
const loadingSelectedBackfill = ref(false)
let pollingTimer: ReturnType<typeof setInterval> | null = null
let selectedRequestId = 0

const run = computed(() => backfill.value?.run ?? null)
const progress = computed(() => {
  if (!run.value || run.value.totalAlbumCount === 0) return 0
  return Math.min(100, Math.round(
    (run.value.processedAlbumCount / run.value.totalAlbumCount) * 100,
  ))
})
const active = computed(() => ['queued', 'running'].includes(run.value?.status ?? ''))
const canCancel = computed(() => ['queued', 'running', 'paused'].includes(run.value?.status ?? ''))
const estimate = computed(() => {
  const milliseconds = run.value?.estimatedRemainingMilliseconds
  return milliseconds && milliseconds > 0
    ? formatApproximateDuration(milliseconds, locale.value)
    : null
})

onMounted(async () => {
  await Promise.all([
    libraryRoots.roots.length === 0 ? libraryRoots.load() : Promise.resolve(),
    loadSelectedScope(),
  ])
  pollingTimer = setInterval(() => {
    if (!active.value) return
    loadSelectedScope({ silent: true }).catch(() => undefined)
  }, 5_000)
})

onUnmounted(() => {
  if (pollingTimer) clearInterval(pollingTimer)
})

watch(selectedLibraryRootId, () => {
  loadSelectedScope().catch(() => undefined)
})

function start() {
  enrichment.startMusicianBackfill(selectedLibraryRootId.value)
    .then(refreshSelectedScope)
    .catch(() => undefined)
}

function pause() {
  if (!run.value) return
  enrichment.pauseMusicianBackfill(run.value.id)
    .then(refreshSelectedScope)
    .catch(() => undefined)
}

function resume() {
  if (!run.value) return
  enrichment.resumeMusicianBackfill(run.value.id)
    .then(refreshSelectedScope)
    .catch(() => undefined)
}

function cancel() {
  if (!run.value) return
  enrichment.cancelMusicianBackfill(run.value.id)
    .then(refreshSelectedScope)
    .catch(() => undefined)
}

function refreshSelectedScope() {
  return loadSelectedScope({ silent: true })
}

async function loadSelectedScope({ silent = false } = {}) {
  const requestId = ++selectedRequestId
  const libraryRootId = selectedLibraryRootId.value
  if (!silent) loadingSelectedBackfill.value = true

  try {
    const result = await enrichment.loadMusicianBackfill(libraryRootId, { silent: true })
    if (requestId === selectedRequestId) backfill.value = result
    return result
  } finally {
    if (requestId === selectedRequestId) loadingSelectedBackfill.value = false
  }
}
</script>

<template>
  <div>
    <div class="text-subtitle-1 font-weight-bold">{{ t('settings.musicianBackfill') }}</div>
    <div class="text-body-2 text-medium-emphasis mb-4">
      {{ t('settings.musicianBackfillHint') }}
    </div>

    <v-select
      v-model="selectedLibraryRootId"
      class="backfill-root"
      clearable
      density="compact"
      :disabled="loadingSelectedBackfill"
      :hint="t('settings.allLibraryRoots')"
      item-title="name"
      item-value="id"
      :items="libraryRoots.roots.filter(root => root.enabled)"
      :label="t('settings.musicianBackfillRoot')"
      :placeholder="t('settings.allLibraryRoots')"
      persistent-hint
      variant="outlined"
    />

    <v-skeleton-loader v-if="loadingSelectedBackfill" type="list-item-two-line" />
    <template v-else-if="backfill">
      <div class="text-body-2 mb-4">
        {{ t('settings.musicianBackfillCoverage', backfill.coverage) }}
      </div>

      <v-alert
        v-if="run"
        class="mb-4"
        :color="run.status === 'failed' || run.status === 'partial' ? 'warning' : undefined"
        icon="mdi-account-music-outline"
        variant="tonal"
      >
        <div class="d-flex flex-wrap align-center justify-space-between ga-2 mb-2">
          <strong>
            {{ t(`settings.musicianBackfillStatuses.${run.status}`) }}
            <template v-if="run.libraryRoot"> · {{ run.libraryRoot.name }}</template>
            <template v-else> · {{ t('settings.allLibraryRoots') }}</template>
          </strong>
          <span class="text-caption">
            {{ run.processedAlbumCount }} / {{ run.totalAlbumCount }}
          </span>
        </div>
        <v-progress-linear
          class="mb-3"
          color="primary"
          :indeterminate="run.status === 'queued' && run.processedAlbumCount === 0"
          :model-value="progress"
          rounded
        />
        <div class="d-flex flex-wrap ga-2">
          <v-chip size="small" variant="tonal">
            {{ t('settings.musicianBackfillFound', { count: run.readyAlbumCount }) }}
          </v-chip>
          <v-chip size="small" variant="tonal">
            {{ t('settings.musicianBackfillNotFound', { count: run.notFoundAlbumCount }) }}
          </v-chip>
          <v-chip size="small" variant="tonal">
            {{ t('settings.musicianBackfillAmbiguous', { count: run.ambiguousAlbumCount }) }}
          </v-chip>
          <v-chip v-if="run.failedAlbumCount" color="warning" size="small" variant="tonal">
            {{ t('settings.musicianBackfillFailed', { count: run.failedAlbumCount }) }}
          </v-chip>
          <v-chip v-if="estimate && active" size="small" variant="tonal">
            {{ t('settings.musicianBackfillRemaining', { duration: estimate }) }}
          </v-chip>
          <v-chip v-if="run.retryAfter" color="warning" size="small" variant="tonal">
            {{ t('settings.musicianBackfillRateLimitedUntil', {
              date: formatDateTime(run.retryAfter, locale),
            }) }}
          </v-chip>
        </div>
        <div v-if="run.lastError && !run.retryAfter" class="text-caption text-medium-emphasis mt-2">
          {{ run.lastError }}
        </div>
      </v-alert>

      <div class="d-flex flex-wrap ga-3">
        <v-btn
          v-if="!backfill.activeRun"
          color="primary"
          :disabled="!enrichment.settings.informationEnabled"
          :loading="enrichment.musicianBackfillOperation === 'start'"
          prepend-icon="mdi-account-sync-outline"
          variant="tonal"
          @click="start"
        >
          {{ t('settings.startMusicianBackfill') }}
        </v-btn>
        <v-btn
          v-if="active && !run?.pauseRequested"
          :loading="enrichment.musicianBackfillOperation === 'pause'"
          prepend-icon="mdi-pause"
          variant="tonal"
          @click="pause"
        >
          {{ t('settings.pause') }}
        </v-btn>
        <v-btn
          v-if="run?.resumable"
          :loading="enrichment.musicianBackfillOperation === 'resume'"
          prepend-icon="mdi-play"
          variant="tonal"
          @click="resume"
        >
          {{ t('settings.resume') }}
        </v-btn>
        <v-btn
          v-if="canCancel"
          color="error"
          :loading="enrichment.musicianBackfillOperation === 'cancel'"
          prepend-icon="mdi-close"
          variant="text"
          @click="cancel"
        >
          {{ t('settings.cancel') }}
        </v-btn>
        <v-btn
          v-if="(run?.ambiguousAlbumCount ?? 0) + (run?.failedAlbumCount ?? 0) > 0"
          prepend-icon="mdi-account-question-outline"
          :to="{ name: 'musician-review' }"
          variant="tonal"
        >
          {{ t('settings.reviewMusicianBackfill') }}
        </v-btn>
      </div>
    </template>
  </div>
</template>

<style scoped>
.backfill-root {
  max-width: 420px;
}
</style>

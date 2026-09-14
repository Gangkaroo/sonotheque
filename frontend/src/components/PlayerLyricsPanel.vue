<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import {
  useOnlineEnrichmentStore,
  type EnrichmentErrorCode,
  type EnrichmentStatus,
} from '@/stores/onlineEnrichment'
import { openExternalUrl } from '@/utils/externalLinks'
import { activeSynchronizedLyricIndex, parseSynchronizedLyrics } from '@/utils/synchronizedLyrics'

const props = defineProps<{
  active: boolean
  currentTime: number
  isPlaying: boolean
  trackId: number | null
}>()

const emit = defineEmits<{
  seek: [seconds: number]
}>()

const { t } = useI18n()
const enrichment = useOnlineEnrichmentStore()
const lyricsContainer = ref<HTMLElement | null>(null)
const synchronizedLyricLines = computed(() => parseSynchronizedLyrics(
  enrichment.lyrics?.data?.synchronizedLyrics,
))
const activeLyricIndex = computed(() => activeSynchronizedLyricIndex(
  synchronizedLyricLines.value,
  props.currentTime,
))

watch(
  [() => props.active, () => props.trackId],
  ([active, trackId]) => {
    if (!active || !trackId) return

    void enrichment.loadLyrics(trackId).then(() => scrollActiveLyricIntoView(activeLyricIndex.value))
  },
  { immediate: true },
)

watch(
  [activeLyricIndex, () => props.active],
  ([index, active]) => {
    if (typeof index !== 'number' || index < 0 || !active) return

    void scrollActiveLyricIntoView(index)
  },
)

function enrichmentStateText(status: EnrichmentStatus | undefined, errorCode?: EnrichmentErrorCode | null) {
  if (status === 'error' && errorCode) return t(`player.enrichmentErrors.${errorCode}`)

  return status ? t(`player.enrichmentStates.${status}`) : ''
}

function formatTime(value: number) {
  const seconds = Math.max(0, Math.floor(value))

  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

async function scrollActiveLyricIntoView(index: number) {
  if (index < 0) return

  await nextTick()
  const line = lyricsContainer.value
    ?.querySelector<HTMLElement>(`[data-lyric-index="${index}"]`)
  const drawer = line?.closest<HTMLElement>('.v-navigation-drawer__content')
  if (!line || !drawer) return

  const lineBounds = line.getBoundingClientRect()
  const drawerBounds = drawer.getBoundingClientRect()
  drawer.scrollTo({
    top: drawer.scrollTop
      + lineBounds.top
      - drawerBounds.top
      - ((drawer.clientHeight - lineBounds.height) / 2),
    behavior: props.isPlaying ? 'smooth' : 'auto',
  })
}
</script>

<template>
  <div v-if="enrichment.lyricsLoading" class="pa-4">
    <v-skeleton-loader type="article" />
  </div>
  <div v-else-if="enrichment.lyricsError" class="pa-4">
    <v-alert type="error" variant="tonal">{{ enrichment.lyricsError }}</v-alert>
  </div>
  <div v-else-if="enrichment.lyrics?.status === 'ready'" class="now-playing-tab-content pa-4">
    <v-alert
      v-if="enrichment.lyrics.stale"
      class="mb-4"
      icon="mdi-cached"
      :text="t('player.enrichmentStale')"
      type="info"
      variant="tonal"
    />
    <div v-if="enrichment.lyrics.data?.instrumental" class="now-playing-empty-state pa-8 text-center">
      <v-icon class="mb-4" color="primary" icon="mdi-music-note-off-outline" size="48" />
      <div class="text-h6 font-weight-bold">{{ t('player.instrumentalTrack') }}</div>
    </div>
    <div
      v-else-if="synchronizedLyricLines.length"
      ref="lyricsContainer"
      class="synchronized-lyrics"
    >
      <button
        v-for="(line, index) in synchronizedLyricLines"
        :key="`${line.timeSeconds}-${index}`"
        :aria-current="index === activeLyricIndex ? 'true' : undefined"
        :aria-label="t('player.seekToLyric', { time: formatTime(line.timeSeconds), lyric: line.text })"
        class="synchronized-lyric-line"
        :class="{
          'synchronized-lyric-line--active': index === activeLyricIndex,
          'synchronized-lyric-line--past': index < activeLyricIndex,
        }"
        :data-lyric-index="index"
        type="button"
        @click="emit('seek', line.timeSeconds)"
      >
        <span class="synchronized-lyric-time">{{ formatTime(line.timeSeconds) }}</span>
        <span>{{ line.text }}</span>
      </button>
    </div>
    <pre v-else class="lyrics-copy">{{ enrichment.lyrics.data?.plainLyrics }}</pre>
    <v-btn
      v-if="enrichment.lyrics.data?.attribution.sourceUrl"
      class="mt-4 px-0"
      append-icon="mdi-open-in-new"
      variant="text"
      @click="openExternalUrl(enrichment.lyrics.data.attribution.sourceUrl)"
    >
      {{ t('player.source', { source: enrichment.lyrics.data.attribution.label }) }}
    </v-btn>
  </div>
  <div v-else class="now-playing-empty-state pa-8 text-center">
    <v-icon class="mb-4" color="primary" icon="mdi-text-box-search-outline" size="48" />
    <div class="text-h6 font-weight-bold">{{ t('player.lyricsUnavailableTitle') }}</div>
    <div class="text-body-2 text-medium-emphasis mt-2">
      {{ enrichmentStateText(enrichment.lyrics?.status, enrichment.lyrics?.errorCode) }}
    </div>
  </div>
</template>

<style scoped>
.now-playing-tab-content {
  max-width: 100%;
}

.lyrics-copy {
  font: inherit;
  line-height: 1.65;
  margin: 0;
  white-space: pre-wrap;
}

.synchronized-lyrics {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding-block: 1rem;
  scroll-behavior: smooth;
}

.synchronized-lyric-line {
  align-items: baseline;
  background: transparent;
  border: 0;
  border-radius: 10px;
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
  display: grid;
  font: inherit;
  gap: 0.75rem;
  grid-template-columns: 2.75rem minmax(0, 1fr);
  line-height: 1.5;
  opacity: 0.58;
  padding: 0.55rem 0.75rem;
  text-align: left;
  transition: background-color 160ms ease, color 160ms ease, opacity 160ms ease;
  width: 100%;
}

.synchronized-lyric-line:hover,
.synchronized-lyric-line:focus-visible {
  background: rgba(var(--v-theme-primary), 0.08);
  opacity: 1;
  outline: none;
}

.synchronized-lyric-line--past {
  opacity: 0.4;
}

.synchronized-lyric-line--active {
  background: rgba(var(--v-theme-primary), 0.14);
  color: rgb(var(--v-theme-primary));
  font-weight: 700;
  opacity: 1;
}

.synchronized-lyric-time {
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  opacity: 0.72;
}

.synchronized-lyric-line > span:last-child {
  min-width: 0;
  overflow-wrap: anywhere;
  white-space: normal;
}

.now-playing-empty-state {
  margin-inline: auto;
  max-width: 420px;
}
</style>

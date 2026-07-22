<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'
import type {
  AudioSimilarityEvaluation,
  AudioSimilarityTrack,
} from '@/stores/audioIntelligenceSettings'
import { usePlayerStore } from '@/stores/player'
import { similarityTrackToPlayableTrack } from '@/utils/audioSimilarity'

const props = defineProps<{
  modelValue: boolean
  trackId: number | null
}>()
const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()
const { locale, t } = useI18n()
const player = usePlayerStore()
const loading = ref(false)
const error = ref<string | null>(null)
const result = ref<AudioSimilarityEvaluation | null>(null)
let requestId = 0
const open = computed({
  get: () => props.modelValue,
  set: value => emit('update:modelValue', value),
})
const playableMatches = computed(() => (
  result.value?.matches.map(similarityTrackToPlayableTrack) ?? []
))

watch(
  [() => props.modelValue, () => props.trackId],
  ([isOpen]) => {
    if (isOpen) void loadMatches()
  },
  { immediate: true },
)

async function loadMatches() {
  const trackId = props.trackId
  if (!trackId) return

  const currentRequestId = ++requestId
  loading.value = true
  error.value = null
  result.value = null
  try {
    const query = new URLSearchParams({
      limit: '10',
      excludeSameAlbum: '1',
      excludeSameArtist: '1',
    })
    const response = await apiRequest<AudioSimilarityEvaluation>(
      `/audio-intelligence/tracks/${trackId}/similar?${query.toString()}`,
    )
    if (currentRequestId === requestId) result.value = response
  } catch (cause) {
    if (currentRequestId !== requestId) return
    error.value = cause instanceof Error ? cause.message : t('tracks.similarTracksLoadFailed')
  } finally {
    if (currentRequestId === requestId) loading.value = false
  }
}

function similarityScore(value: number) {
  return new Intl.NumberFormat(locale.value, {
    maximumFractionDigits: 1,
    style: 'percent',
  }).format(value)
}

function featureBpm(value: number | undefined) {
  return value === undefined ? null : t('settings.audioSimilarityBpm', { value: Math.round(value) })
}

function featureKey(track: AudioSimilarityTrack) {
  if (!track.features.key) return null

  return [track.features.key, track.features.scale].filter(Boolean).join(' ')
}

function playMatch(match: AudioSimilarityTrack) {
  const track = similarityTrackToPlayableTrack(match)
  player.playTrack(track, [track], 'track-list')
}

function playMatches() {
  const [firstTrack] = playableMatches.value
  if (!firstTrack) return

  player.playTrack(firstTrack, playableMatches.value, 'track-list')
  open.value = false
}

function queueMatches() {
  if (!playableMatches.value.length) return

  player.queueTracks(playableMatches.value, 'track-list')
  open.value = false
}
</script>

<template>
  <v-dialog v-model="open" max-width="760" scrollable>
    <v-card prepend-icon="mdi-vector-link" :title="t('tracks.similarTracks')">
      <template #append>
        <v-btn
          :aria-label="t('tracks.close')"
          icon="mdi-close"
          :title="t('tracks.close')"
          variant="text"
          @click="open = false"
        />
      </template>

      <v-card-text>
        <v-skeleton-loader v-if="loading" type="list-item-three-line@5" />
        <v-alert v-else-if="error" type="warning" variant="tonal">
          {{ error }}
        </v-alert>
        <template v-else-if="result">
          <div class="text-body-2 text-medium-emphasis mb-3">
            {{ t('tracks.similarTracksDescription', { track: result.source.label }) }}
          </div>
          <v-list v-if="result.matches.length" border lines="two" rounded="lg">
            <v-list-item
              v-for="match in result.matches"
              :key="match.id"
              class="similar-track-row"
              @click="playMatch(match)"
            >
              <template #prepend>
                <v-avatar color="primary" size="40" variant="tonal">
                  <v-icon icon="mdi-music-note" />
                </v-avatar>
              </template>
              <v-list-item-title>
                <RouterLink
                  class="similar-track-link font-weight-bold"
                  :to="{ name: 'track-detail', params: { id: match.id } }"
                  @click.stop="open = false"
                >
                  {{ match.title }}
                </RouterLink>
              </v-list-item-title>
              <v-list-item-subtitle>
                <template v-for="(artist, index) in match.artists" :key="artist.id">
                  <span v-if="index > 0">, </span>
                  <RouterLink
                    class="similar-track-link"
                    :to="{ name: 'artist-detail', params: { id: artist.id } }"
                    @click.stop="open = false"
                  >
                    {{ artist.name }}
                  </RouterLink>
                </template>
                <template v-if="match.albumId">
                  <span> · </span>
                  <RouterLink
                    class="similar-track-link"
                    :to="{ name: 'album-detail', params: { id: match.albumId } }"
                    @click.stop="open = false"
                  >
                    {{ match.albumTitle }}
                  </RouterLink>
                </template>
                <span v-if="match.year"> · {{ match.year }}</span>
              </v-list-item-subtitle>
              <template #append>
                <div class="similar-track-meta">
                  <v-chip color="primary" size="small" variant="tonal">
                    {{ similarityScore(match.similarity) }}
                  </v-chip>
                  <div class="d-flex justify-end ga-1 mt-1">
                    <v-chip v-if="featureBpm(match.features.bpm)" size="x-small" variant="outlined">
                      {{ featureBpm(match.features.bpm) }}
                    </v-chip>
                    <v-chip v-if="featureKey(match)" size="x-small" variant="outlined">
                      {{ featureKey(match) }}
                    </v-chip>
                    <v-btn
                      color="primary"
                      density="compact"
                      icon="mdi-play"
                      size="x-small"
                      :title="t('tracks.playTrack')"
                      variant="text"
                      @click.stop="playMatch(match)"
                    />
                  </div>
                </div>
              </template>
            </v-list-item>
          </v-list>
          <v-alert v-else type="info" variant="tonal">
            {{ t('tracks.noSimilarTracks') }}
          </v-alert>
        </template>
      </v-card-text>

      <v-card-actions class="similar-track-actions">
        <v-spacer />
        <v-btn variant="text" @click="open = false">{{ t('tracks.close') }}</v-btn>
        <v-btn
          :disabled="!playableMatches.length"
          prepend-icon="mdi-playlist-plus"
          variant="tonal"
          @click="queueMatches"
        >
          {{ t('tracks.queueSimilarTracks') }}
        </v-btn>
        <v-btn
          color="primary"
          :disabled="!playableMatches.length"
          prepend-icon="mdi-play"
          variant="flat"
          @click="playMatches"
        >
          {{ t('tracks.playSimilarTracks') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.similar-track-row {
  cursor: pointer;
}

.similar-track-link {
  color: inherit;
  text-decoration: none;
}

.similar-track-link:hover,
.similar-track-link:focus-visible {
  color: rgb(var(--v-theme-primary));
  text-decoration: underline;
}

.similar-track-meta {
  min-width: 112px;
  text-align: right;
}

.similar-track-actions {
  flex-wrap: wrap;
}

@media (max-width: 600px) {
  .similar-track-meta {
    min-width: auto;
  }
}
</style>

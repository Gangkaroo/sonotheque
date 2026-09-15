<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import type {
  AudioSimilarityEvaluation,
  AudioSimilaritySourceProgress,
  AudioSimilarityTrack,
} from '@/stores/audioIntelligenceSettings'
import { useAudioIntelligenceSettingsStore } from '@/stores/audioIntelligenceSettings'
import { usePlayerStore } from '@/stores/player'
import { similarityTrackToPlayableTrack } from '@/utils/audioSimilarity'

const props = defineProps<{
  currentReviewProgress: AudioSimilaritySourceProgress | null
  excludeSameAlbum: boolean
  excludeSameArtist: boolean
  result: AudioSimilarityEvaluation
}>()

const { locale, t } = useI18n()
const audioIntelligence = useAudioIntelligenceSettingsStore()
const player = usePlayerStore()

function similarityScore(value: number) {
  return new Intl.NumberFormat(locale.value, {
    maximumFractionDigits: 1,
    style: 'percent',
  }).format(value)
}

function featureBpm(value: number | undefined) {
  return value === undefined
    ? null
    : t('settings.audioSimilarityBpm', { value: Math.round(value) })
}

function featureKey(key: string | undefined, scale: string | undefined) {
  if (!key) return null

  return [key, scale].filter(Boolean).join(' ')
}

function isPlaying(trackId: number) {
  return player.currentTrack?.id === trackId && player.isPlaying
}

function togglePlayback(track: AudioSimilarityTrack) {
  if (player.currentTrack?.id === track.id) {
    if (player.isPlaying) {
      player.pause()
    } else {
      player.resume()
    }
    return
  }

  const playableTrack = similarityTrackToPlayableTrack(track)
  player.playTrack(playableTrack, [playableTrack], 'track-list')
}

function playEvaluationTracks() {
  const tracks = [props.result.source, ...props.result.matches].map(similarityTrackToPlayableTrack)
  const sourceTrack = tracks[0]

  if (sourceTrack) player.playTrack(sourceTrack, tracks, 'track-list')
}

async function toggleMatchFeedback(
  candidateId: number,
  feedback: 'relevant' | 'irrelevant',
) {
  const match = props.result.matches.find(item => item.id === candidateId)
  await audioIntelligence.setSimilarityFeedback(
    props.result.source.id,
    candidateId,
    match?.feedback === feedback ? null : feedback,
    {
      excludeSameAlbum: props.excludeSameAlbum,
      excludeSameArtist: props.excludeSameArtist,
    },
  )
}
</script>

<template>
  <div class="mt-5">
    <div class="d-flex flex-wrap justify-space-between align-center ga-2 mb-2">
      <div>
        <div class="text-subtitle-2 font-weight-bold">
          {{ t('settings.audioSimilarityMatchesFor', { track: result.source.label }) }}
        </div>
        <div class="text-caption text-medium-emphasis">
          {{ t(result.ranking.method === 'embedding'
            ? 'settings.audioSimilarityCalculation'
            : result.ranking.method === 'personalized'
              ? 'settings.audioSimilarityPersonalizedCalculation'
              : 'settings.audioSimilarityRefinedCalculation', {
            candidates: result.candidateCount,
            pool: result.ranking.candidatePoolSize,
            milliseconds: result.calculationMs,
          }) }}
        </div>
        <div v-if="currentReviewProgress" class="text-caption text-medium-emphasis">
          {{ t('settings.audioSimilaritySourceProgress', {
            rated: currentReviewProgress.rated,
            required: currentReviewProgress.required,
          }) }}
        </div>
        <div class="d-flex flex-wrap ga-1 mt-1">
          <v-chip v-if="featureBpm(result.source.features.bpm)" size="x-small" variant="outlined">
            {{ featureBpm(result.source.features.bpm) }}
          </v-chip>
          <v-chip
            v-if="featureKey(result.source.features.key, result.source.features.scale)"
            size="x-small"
            variant="outlined"
          >
            {{ featureKey(result.source.features.key, result.source.features.scale) }}
          </v-chip>
        </div>
      </div>
      <div class="d-flex flex-wrap align-center justify-end ga-1">
        <v-chip size="small" variant="outlined">
          {{ result.profile.modelName }}
        </v-chip>
        <v-btn
          color="primary"
          prepend-icon="mdi-playlist-play"
          size="small"
          :title="t('settings.audioSimilarityPlayAll')"
          variant="tonal"
          @click="playEvaluationTracks"
        >
          {{ t('settings.audioSimilarityPlayAll') }}
        </v-btn>
        <v-btn
          :aria-label="t('settings.audioSimilarityOpenSourceDetails')"
          icon="mdi-information-outline"
          size="small"
          :title="t('settings.audioSimilarityOpenSourceDetails')"
          :to="{
            name: 'track-detail',
            params: { id: result.source.id },
            query: { backTo: 'audio-intelligence' },
          }"
          variant="text"
        />
        <v-btn
          color="primary"
          :icon="isPlaying(result.source.id) ? 'mdi-pause' : 'mdi-play'"
          size="small"
          :title="isPlaying(result.source.id)
            ? t('player.pause')
            : t('settings.audioSimilarityPlaySource')"
          variant="tonal"
          @click="togglePlayback(result.source)"
        />
      </div>
    </div>

    <v-list border class="audio-similarity-results" lines="two" rounded="lg">
      <v-list-item
        v-for="match in result.matches"
        :key="match.id"
        class="audio-similarity-result"
        :class="{ 'audio-similarity-result--active': player.currentTrack?.id === match.id }"
        @click="togglePlayback(match)"
      >
        <template #prepend>
          <v-avatar color="primary" size="40" variant="tonal">
            <v-icon icon="mdi-music-note" />
          </v-avatar>
        </template>
        <v-list-item-title>
          <RouterLink
            class="audio-similarity-link font-weight-bold"
            :to="{
              name: 'track-detail',
              params: { id: match.id },
              query: { backTo: 'audio-intelligence' },
            }"
            @click.stop
          >
            {{ match.title }}
          </RouterLink>
        </v-list-item-title>
        <v-list-item-subtitle>
          <template v-if="match.artists.length">
            <template v-for="(artist, index) in match.artists" :key="artist.id">
              <span v-if="index > 0">, </span>
              <RouterLink
                class="audio-similarity-link"
                :to="{
                  name: 'artist-detail',
                  params: { id: artist.id },
                  query: { backTo: 'audio-intelligence' },
                }"
                @click.stop
              >
                {{ artist.name }}
              </RouterLink>
            </template>
          </template>
          <span v-else>{{ t('catalog.unknownArtist') }}</span>
          <template v-if="match.albumId">
            <span> · </span>
            <RouterLink
              class="audio-similarity-link"
              :to="{
                name: 'album-detail',
                params: { id: match.albumId },
                query: { backTo: 'audio-intelligence' },
              }"
              @click.stop
            >
              {{ match.albumTitle }}
            </RouterLink>
          </template>
          <span v-if="match.year"> · {{ match.year }}</span>
        </v-list-item-subtitle>
        <template #append>
          <div class="audio-similarity-match-meta">
            <v-chip
              color="primary"
              size="small"
              :title="t('settings.audioSimilarityScoreDetails', {
                vector: similarityScore(match.similarity),
                ranked: similarityScore(match.rankingScore),
              })"
              variant="tonal"
            >
              {{ similarityScore(match.rankingScore) }}
            </v-chip>
            <div class="d-flex justify-end ga-1 mt-1">
              <v-chip v-if="featureBpm(match.features.bpm)" size="x-small" variant="outlined">
                {{ featureBpm(match.features.bpm) }}
              </v-chip>
              <v-chip
                v-if="featureKey(match.features.key, match.features.scale)"
                size="x-small"
                variant="outlined"
              >
                {{ featureKey(match.features.key, match.features.scale) }}
              </v-chip>
              <v-btn
                color="primary"
                density="compact"
                :icon="isPlaying(match.id) ? 'mdi-pause' : 'mdi-play'"
                size="x-small"
                :title="isPlaying(match.id) ? t('player.pause') : t('settings.audioSimilarityPlayMatch')"
                variant="text"
                @click.prevent.stop="togglePlayback(match)"
              />
              <v-btn
                :aria-pressed="match.feedback === 'relevant'"
                :class="{
                  'audio-feedback-button--subdued': match.feedback !== null && match.feedback !== 'relevant',
                }"
                color="success"
                density="compact"
                :icon="match.feedback === 'relevant' ? 'mdi-thumb-up' : 'mdi-thumb-up-outline'"
                :loading="audioIntelligence.ratingTrackId === match.id"
                size="x-small"
                :title="t('settings.audioSimilarityRelevant')"
                :variant="match.feedback === 'relevant' ? 'tonal' : 'text'"
                @click.prevent.stop="toggleMatchFeedback(match.id, 'relevant')"
              />
              <v-btn
                :aria-pressed="match.feedback === 'irrelevant'"
                :class="{
                  'audio-feedback-button--subdued': match.feedback !== null && match.feedback !== 'irrelevant',
                }"
                color="error"
                density="compact"
                :icon="match.feedback === 'irrelevant' ? 'mdi-thumb-down' : 'mdi-thumb-down-outline'"
                :loading="audioIntelligence.ratingTrackId === match.id"
                size="x-small"
                :title="t('settings.audioSimilarityIrrelevant')"
                :variant="match.feedback === 'irrelevant' ? 'tonal' : 'text'"
                @click.prevent.stop="toggleMatchFeedback(match.id, 'irrelevant')"
              />
            </div>
          </div>
        </template>
      </v-list-item>
    </v-list>
  </div>
</template>

<style scoped>
.audio-similarity-results {
  overflow: hidden;
}

.audio-similarity-result {
  cursor: pointer;
}

.audio-similarity-result--active {
  background: rgba(var(--v-theme-primary), 0.08);
}

.audio-similarity-link {
  color: inherit;
  text-decoration: none;
}

.audio-similarity-link:hover,
.audio-similarity-link:focus-visible {
  color: rgb(var(--v-theme-primary));
  text-decoration: underline;
}

.audio-similarity-match-meta {
  min-width: 112px;
  text-align: right;
}

.audio-feedback-button--subdued {
  opacity: 0.35;
  transition: opacity 120ms ease;
}

.audio-feedback-button--subdued:hover,
.audio-feedback-button--subdued:focus-visible {
  opacity: 0.75;
}

@media (max-width: 760px) {
  .audio-similarity-match-meta {
    min-width: auto;
  }
}
</style>

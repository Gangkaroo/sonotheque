<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import AudioAnalysisRunStatus from '@/components/AudioAnalysisRunStatus.vue'
import {
  type AudioSimilarityConfiguration,
  type AudioSimilarityTrack,
  useAudioIntelligenceSettingsStore,
} from '@/stores/audioIntelligenceSettings'
import { usePlayerStore } from '@/stores/player'
import { similarityTrackToPlayableTrack } from '@/utils/audioSimilarity'

const { locale, t } = useI18n()
const audioIntelligence = useAudioIntelligenceSettingsStore()
const player = usePlayerStore()

const validationSampleSize = ref(200)
const expansionTarget = ref(500)
const evaluationTrackId = ref<number | null>(null)
const excludeSameAlbum = ref(true)
const excludeSameArtist = ref(true)

const validationSampleSizeValid = computed(() => (
  Number.isInteger(validationSampleSize.value)
  && validationSampleSize.value >= 50
  && validationSampleSize.value <= 500
))
const analyzedTrackCount = computed(() => audioIntelligence.evaluation.analyzedTrackCount)
const hasAnalyzedPool = computed(() => analyzedTrackCount.value > 0)
const reviewPoolAtMaximum = computed(() => analyzedTrackCount.value >= 500)
const expansionTargetValid = computed(() => (
  Number.isInteger(expansionTarget.value)
  && expansionTarget.value >= 50
  && expansionTarget.value <= 500
  && expansionTarget.value > analyzedTrackCount.value
))
const validationRun = computed(() => audioIntelligence.settings.latestValidationRun)
const analysisRunBlocksNewWork = computed(() => (
  audioIntelligence.settings.activeRun !== null
))
const resumableValidationName = computed(() => {
  if (validationRun.value?.kind === 'expansion') {
    return t('settings.audioPoolExpansionName')
  }

  return t('settings.audioValidationSampleName')
})
const distributionFeatures = computed(() => (
  ['bpm', 'danceability', 'dynamicComplexity', 'loudness']
    .flatMap((key) => {
      const distribution = audioIntelligence.evaluation.distributions[key]

      return distribution ? [{ key, distribution }] : []
    })
))
const activeReviewConfiguration = computed<AudioSimilarityConfiguration>(() => {
  if (excludeSameAlbum.value && excludeSameArtist.value) return 'exclude_album_artist'
  if (excludeSameAlbum.value) return 'exclude_album'
  if (excludeSameArtist.value) return 'exclude_artist'

  return 'all'
})
const activeReviewQuality = computed(() => (
  audioIntelligence.evaluation.review.quality[activeReviewConfiguration.value]
))
const currentReviewSource = computed(() => (
  audioIntelligence.evaluation.review.sources.find(
    source => source.id === audioIntelligence.evaluationResult?.source.id,
  )
))
const currentReviewProgress = computed(() => (
  currentReviewSource.value?.configurations[activeReviewConfiguration.value]
))
const nextReviewSource = computed(() => {
  const sources = audioIntelligence.evaluation.review.sources
  const configuration = activeReviewConfiguration.value

  return sources.find((source) => {
    const progress = source.configurations[configuration]

    return progress.rated > 0 && !progress.complete
  }) ?? sources.find(source => !source.configurations[configuration].complete)
})

watch(
  () => audioIntelligence.settings.validationSampleSize,
  (value) => {
    validationSampleSize.value = value
  },
  { immediate: true },
)
watch([excludeSameAlbum, excludeSameArtist], () => {
  audioIntelligence.evaluationResult = null
})

async function saveValidationSampleSize() {
  if (!validationSampleSizeValid.value) return

  await audioIntelligence.save(
    audioIntelligence.settings.enabled,
    validationSampleSize.value,
  )
}

function setValidationSampleSize(value: string | number | null) {
  validationSampleSize.value = Number(value)
}

function setExpansionTarget(value: string | number | null) {
  expansionTarget.value = Number(value)
}

function similarityScore(value: number) {
  return new Intl.NumberFormat(locale.value, {
    maximumFractionDigits: 1,
    style: 'percent',
  }).format(value)
}

function percentage(value: number | null) {
  if (value === null) return '–'

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
  const result = audioIntelligence.evaluationResult
  if (!result) return

  const tracks = [result.source, ...result.matches].map(similarityTrackToPlayableTrack)
  const sourceTrack = tracks[0]

  if (sourceTrack) {
    player.playTrack(sourceTrack, tracks, 'track-list')
  }
}

function featureKey(key: string | undefined, scale: string | undefined) {
  if (!key) return null

  return [key, scale].filter(Boolean).join(' ')
}

function featureValue(key: string, value: number) {
  return new Intl.NumberFormat(locale.value, {
    maximumFractionDigits: key === 'bpm' ? 1 : 2,
  }).format(value)
}

function distributionBarHeight(count: number, maximum: number) {
  return `${Math.max(4, Math.round((count / Math.max(1, maximum)) * 100))}%`
}

function distributionBinTitle(
  key: string,
  bin: { minimum: number, maximum: number, count: number },
) {
  return t('settings.audioFeatureDistributionBin', {
    minimum: featureValue(key, bin.minimum),
    maximum: featureValue(key, bin.maximum),
    count: bin.count,
  })
}

async function evaluateSelectedTrack() {
  if (evaluationTrackId.value === null) return

  await audioIntelligence.evaluateTrack(evaluationTrackId.value, {
    excludeSameAlbum: excludeSameAlbum.value,
    excludeSameArtist: excludeSameArtist.value,
  })
}

async function reviewNextTrack() {
  if (!nextReviewSource.value) return

  evaluationTrackId.value = nextReviewSource.value.id
  await evaluateSelectedTrack()
}

async function toggleMatchFeedback(
  candidateId: number,
  feedback: 'relevant' | 'irrelevant',
) {
  const result = audioIntelligence.evaluationResult
  if (!result) return

  const match = result.matches.find(item => item.id === candidateId)
  await audioIntelligence.setSimilarityFeedback(
    result.source.id,
    candidateId,
    match?.feedback === feedback ? null : feedback,
    {
      excludeSameAlbum: excludeSameAlbum.value,
      excludeSameArtist: excludeSameArtist.value,
    },
  )
}
</script>

<template>
  <v-divider class="my-6" />

  <div class="text-subtitle-1 font-weight-bold">
    {{ t('settings.audioValidationSample') }}
  </div>
  <div class="text-body-2 text-medium-emphasis mb-4">
    {{ t('settings.audioValidationSampleDescription', {
      eligible: audioIntelligence.settings.eligibleTrackCount,
      fingerprinted: audioIntelligence.settings.fingerprintedTrackCount,
    }) }}
  </div>
  <v-alert
    v-if="audioIntelligence.settings.eligibleTrackCount === 0"
    class="mb-4"
    icon="mdi-database-sync-outline"
    :text="t('settings.audioValidationSampleNeedsTracks')"
    type="warning"
    variant="tonal"
  />
  <v-alert
    v-if="reviewPoolAtMaximum"
    class="mb-4"
    icon="mdi-check-circle-outline"
    :text="t('settings.audioPoolMaximumReached', { count: analyzedTrackCount })"
    type="success"
    variant="tonal"
  />
  <div class="audio-validation-controls">
    <template v-if="!hasAnalyzedPool">
      <v-text-field
        density="comfortable"
        :error="!validationSampleSizeValid"
        :hint="t('settings.audioValidationSampleSizeHint')"
        inputmode="numeric"
        :label="t('settings.audioValidationSampleSize')"
        max="500"
        min="50"
        :model-value="validationSampleSize"
        persistent-hint
        step="50"
        type="number"
        @update:model-value="setValidationSampleSize"
      />
      <v-btn
        :disabled="!validationSampleSizeValid
          || validationSampleSize === audioIntelligence.settings.validationSampleSize"
        :loading="audioIntelligence.saving"
        variant="tonal"
        @click="saveValidationSampleSize"
      >
        {{ t('settings.saveValidationSampleSize') }}
      </v-btn>
      <v-btn
        color="primary"
        :disabled="!audioIntelligence.settings.enabled
          || !validationSampleSizeValid
          || audioIntelligence.settings.eligibleTrackCount === 0
          || analysisRunBlocksNewWork
          || validationSampleSize
            !== audioIntelligence.settings.validationSampleSize"
        :loading="audioIntelligence.preparingValidationSample"
        prepend-icon="mdi-flask-outline"
        variant="flat"
        @click="audioIntelligence.prepareValidationSample"
      >
        {{ t('settings.prepareAudioValidationSample') }}
      </v-btn>
    </template>
    <template v-else-if="!reviewPoolAtMaximum">
      <v-text-field
        density="comfortable"
        :error="!expansionTargetValid"
        :hint="t('settings.audioPoolTargetHint', { count: analyzedTrackCount })"
        inputmode="numeric"
        :label="t('settings.audioPoolTarget')"
        max="500"
        :min="analyzedTrackCount + 1"
        :model-value="expansionTarget"
        persistent-hint
        step="50"
        type="number"
        @update:model-value="setExpansionTarget"
      />
      <v-tooltip location="top" :text="t('settings.prepareAudioPoolExpansionTooltip')">
        <template #activator="{ props }">
          <span v-bind="props" class="d-inline-flex">
            <v-btn
              color="primary"
              :disabled="!audioIntelligence.settings.enabled
                || !expansionTargetValid
                || audioIntelligence.settings.analyzerStatus !== 'ready'
                || analysisRunBlocksNewWork"
              :loading="audioIntelligence.expandingPool"
              prepend-icon="mdi-chart-timeline-variant-shimmer"
              variant="flat"
              @click="audioIntelligence.expandPool(expansionTarget)"
            >
              {{ t('settings.expandAudioPool') }}
            </v-btn>
          </span>
        </template>
      </v-tooltip>
    </template>
    <v-tooltip
      v-if="validationRun"
      location="top"
      :text="validationRun.summary.mode === 'expansion'
        ? t('settings.runAudioPoolExpansionTooltip')
        : t('settings.runAudioValidationTooltip')"
    >
      <template #activator="{ props }">
        <span v-bind="props" class="d-inline-flex">
          <v-btn
            color="primary"
            :disabled="validationRun.status !== 'prepared'
              || audioIntelligence.settings.analyzerStatus !== 'ready'"
            :loading="audioIntelligence.startingRun"
            prepend-icon="mdi-play"
            variant="tonal"
            @click="audioIntelligence.startRun(validationRun.id)"
          >
            {{ validationRun.summary.mode === 'expansion'
              ? t('settings.runAudioExpansion')
              : t('settings.runAudioValidation') }}
          </v-btn>
        </span>
      </template>
    </v-tooltip>
    <v-btn
      v-if="validationRun
        && ['fingerprinting', 'prepared', 'queued', 'running', 'paused'].includes(
          validationRun.status,
        )"
      color="error"
      :disabled="validationRun.cancelRequestedAt !== null"
      :loading="audioIntelligence.cancellingRun"
      prepend-icon="mdi-stop-circle-outline"
      variant="tonal"
      @click="audioIntelligence.cancelRun(validationRun.id)"
    >
      {{ validationRun.cancelRequestedAt
        ? t('settings.audioAnalysisCancelling')
        : t('settings.cancelAudioAnalysis') }}
    </v-btn>
    <v-btn
      v-if="validationRun
        && ['fingerprinting', 'queued', 'running'].includes(validationRun.status)"
      color="primary"
      :disabled="validationRun.pauseRequestedAt !== null"
      :loading="audioIntelligence.pausingRun"
      prepend-icon="mdi-pause-circle-outline"
      variant="tonal"
      @click="audioIntelligence.pauseRun(validationRun.id)"
    >
      {{ validationRun.pauseRequestedAt
        ? t('settings.audioAnalysisPausing')
        : t('settings.pauseAudioAnalysis') }}
    </v-btn>
    <v-tooltip
      v-if="validationRun?.resumable"
      location="top"
      :text="t('settings.resumeAudioAnalysisTooltip', {
        scope: resumableValidationName,
      })"
    >
      <template #activator="{ props }">
        <span v-bind="props" class="d-inline-flex">
          <v-btn
            color="primary"
            :loading="audioIntelligence.resumingRun"
            prepend-icon="mdi-restart"
            variant="tonal"
            @click="audioIntelligence.resumeRun(
              validationRun.id,
            )"
          >
            {{ t('settings.resumeScopedAudioAnalysis', {
              scope: resumableValidationName,
            }) }}
          </v-btn>
        </span>
      </template>
    </v-tooltip>
  </div>

  <AudioAnalysisRunStatus
    v-if="validationRun"
    class="mt-5"
    :run="validationRun"
  />

  <v-divider class="my-6" />

  <div class="audio-expansion-title mb-5">
    <div>
      <div class="text-subtitle-1 font-weight-bold">
        {{ t('settings.audioSimilarityEvaluation') }}
      </div>
      <div class="text-body-2 text-medium-emphasis">
        {{ t('settings.audioSimilarityEvaluationDescription') }}
      </div>
    </div>
    <v-chip
      prepend-icon="mdi-music-box-multiple-outline"
      size="small"
      variant="tonal"
    >
      {{ t('settings.audioSimilarityAnalyzedTracks', {
        count: audioIntelligence.evaluation.analyzedTrackCount,
      }) }}
    </v-chip>
  </div>

  <v-alert
    v-if="audioIntelligence.evaluationError"
    class="mb-4"
    type="error"
    variant="tonal"
  >
    {{ audioIntelligence.evaluationError }}
  </v-alert>
  <v-skeleton-loader
    v-if="audioIntelligence.loadingEvaluation"
    type="list-item-two-line"
  />
  <v-alert
    v-else-if="audioIntelligence.evaluation.analyzedTrackCount === 0"
    icon="mdi-flask-empty-outline"
    :text="t('settings.audioSimilarityNeedsResults')"
    type="info"
    variant="tonal"
  />
  <template v-else>
    <div class="d-flex flex-wrap ga-2 mb-4">
      <v-chip prepend-icon="mdi-folder-multiple-outline" size="small" variant="tonal">
        {{ t('settings.audioSimilarityRootCoverage', {
          count: audioIntelligence.evaluation.coverage.rootCount,
        }) }}
      </v-chip>
      <v-chip prepend-icon="mdi-account-music-outline" size="small" variant="tonal">
        {{ t('settings.audioSimilarityArtistCoverage', {
          count: audioIntelligence.evaluation.coverage.artistCount,
        }) }}
      </v-chip>
      <v-chip prepend-icon="mdi-album" size="small" variant="tonal">
        {{ t('settings.audioSimilarityAlbumCoverage', {
          count: audioIntelligence.evaluation.coverage.albumCount,
        }) }}
      </v-chip>
      <v-chip color="success" prepend-icon="mdi-thumb-up-outline" size="small" variant="tonal">
        {{ audioIntelligence.evaluation.feedbackSummary.relevant }}
      </v-chip>
      <v-chip color="error" prepend-icon="mdi-thumb-down-outline" size="small" variant="tonal">
        {{ audioIntelligence.evaluation.feedbackSummary.irrelevant }}
      </v-chip>
    </div>

    <div v-if="distributionFeatures.length > 0" class="audio-feature-grid mb-5">
      <v-card
        v-for="feature in distributionFeatures"
        :key="feature.key"
        border
        class="audio-feature-card"
        rounded="lg"
        variant="tonal"
      >
        <v-card-text class="pa-4">
          <div class="d-flex align-center justify-space-between ga-2 mb-3">
            <div class="text-subtitle-2 font-weight-bold">
              {{ t(`settings.audioFeatureNames.${feature.key}`) }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('settings.audioFeatureMedian', {
                value: featureValue(feature.key, feature.distribution.median),
              }) }}
            </div>
          </div>
          <div class="audio-feature-bars" aria-hidden="true">
            <div
              v-for="(bin, index) in feature.distribution.bins"
              :key="index"
              class="audio-feature-bin"
              :style="{
                height: distributionBarHeight(
                  bin.count,
                  Math.max(...feature.distribution.bins.map(item => item.count)),
                ),
              }"
              :title="distributionBinTitle(feature.key, bin)"
            />
          </div>
          <div class="d-flex justify-space-between text-caption text-medium-emphasis mt-1">
            <span>{{ featureValue(feature.key, feature.distribution.minimum) }}</span>
            <span>{{ featureValue(feature.key, feature.distribution.maximum) }}</span>
          </div>
        </v-card-text>
      </v-card>
    </div>

    <v-card border class="audio-review-card mb-5" rounded="lg" variant="tonal">
      <v-card-text class="pa-4">
        <div class="d-flex flex-wrap align-start justify-space-between ga-3 mb-3">
          <div>
            <div class="text-subtitle-2 font-weight-bold">
              {{ t('settings.audioSimilarityReview') }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('settings.audioSimilarityReviewDescription', {
                sources: audioIntelligence.evaluation.review.targetSourceCount,
                matches: audioIntelligence.evaluation.review.matchCount,
              }) }}
            </div>
          </div>
          <v-btn
            color="primary"
            :disabled="!nextReviewSource"
            :loading="audioIntelligence.evaluatingTrack"
            prepend-icon="mdi-clipboard-text-search-outline"
            variant="tonal"
            @click="reviewNextTrack"
          >
            {{ nextReviewSource
              ? t('settings.audioSimilarityReviewNext')
              : t('settings.audioSimilarityReviewComplete') }}
          </v-btn>
        </div>
        <v-progress-linear
          color="primary"
          height="8"
          :max="Math.max(1, audioIntelligence.evaluation.review.targetSourceCount)"
          rounded
          :model-value="activeReviewQuality.completedSourceCount"
        />
        <div class="d-flex flex-wrap ga-2 mt-3">
          <v-chip prepend-icon="mdi-check-circle-outline" size="small" variant="outlined">
            {{ t('settings.audioSimilarityReviewedSources', {
              completed: activeReviewQuality.completedSourceCount,
              total: audioIntelligence.evaluation.review.targetSourceCount,
            }) }}
          </v-chip>
          <v-chip prepend-icon="mdi-format-list-checks" size="small" variant="outlined">
            {{ t('settings.audioSimilarityRatedMatches', {
              count: activeReviewQuality.ratedMatchCount,
            }) }}
          </v-chip>
          <v-chip prepend-icon="mdi-chart-donut" size="small" variant="outlined">
            {{ t('settings.audioSimilarityRelevanceRate', {
              value: percentage(activeReviewQuality.relevanceRate),
            }) }}
          </v-chip>
          <v-chip prepend-icon="mdi-chart-box-outline" size="small" variant="outlined">
            {{ t('settings.audioSimilarityCompletedSourceQuality', {
              value: percentage(activeReviewQuality.meanRelevantShare),
            }) }}
          </v-chip>
        </div>
      </v-card-text>
    </v-card>

    <div class="audio-similarity-controls">
      <v-autocomplete
        v-model="evaluationTrackId"
        clearable
        density="comfortable"
        :items="audioIntelligence.evaluation.tracks"
        item-title="label"
        item-value="id"
        :label="t('settings.audioSimilaritySourceTrack')"
        prepend-inner-icon="mdi-music-note-search"
      />
      <div class="audio-similarity-filters">
        <v-switch
          v-model="excludeSameArtist"
          color="primary"
          density="compact"
          hide-details
          :label="t('settings.audioSimilarityExcludeArtist')"
        />
        <v-switch
          v-model="excludeSameAlbum"
          color="primary"
          density="compact"
          hide-details
          :label="t('settings.audioSimilarityExcludeAlbum')"
        />
      </div>
      <v-btn
        color="primary"
        :disabled="evaluationTrackId === null"
        :loading="audioIntelligence.evaluatingTrack"
        prepend-icon="mdi-vector-link"
        variant="tonal"
        @click="evaluateSelectedTrack"
      >
        {{ t('settings.evaluateAudioSimilarity') }}
      </v-btn>
    </div>

    <div v-if="audioIntelligence.evaluationResult" class="mt-5">
      <div class="d-flex flex-wrap justify-space-between align-center ga-2 mb-2">
        <div>
          <div class="text-subtitle-2 font-weight-bold">
            {{ t('settings.audioSimilarityMatchesFor', {
              track: audioIntelligence.evaluationResult.source.label,
            }) }}
          </div>
          <div class="text-caption text-medium-emphasis">
            {{ t(audioIntelligence.evaluationResult.ranking.method === 'embedding'
              ? 'settings.audioSimilarityCalculation'
              : audioIntelligence.evaluationResult.ranking.method === 'personalized'
                ? 'settings.audioSimilarityPersonalizedCalculation'
                : 'settings.audioSimilarityRefinedCalculation', {
              candidates: audioIntelligence.evaluationResult.candidateCount,
              pool: audioIntelligence.evaluationResult.ranking.candidatePoolSize,
              milliseconds: audioIntelligence.evaluationResult.calculationMs,
            }) }}
          </div>
          <div v-if="currentReviewProgress" class="text-caption text-medium-emphasis">
            {{ t('settings.audioSimilaritySourceProgress', {
              rated: currentReviewProgress.rated,
              required: currentReviewProgress.required,
            }) }}
          </div>
          <div class="d-flex flex-wrap ga-1 mt-1">
            <v-chip
              v-if="featureBpm(audioIntelligence.evaluationResult.source.features.bpm)"
              size="x-small"
              variant="outlined"
            >
              {{ featureBpm(audioIntelligence.evaluationResult.source.features.bpm) }}
            </v-chip>
            <v-chip
              v-if="featureKey(
                audioIntelligence.evaluationResult.source.features.key,
                audioIntelligence.evaluationResult.source.features.scale,
              )"
              size="x-small"
              variant="outlined"
            >
              {{ featureKey(
                audioIntelligence.evaluationResult.source.features.key,
                audioIntelligence.evaluationResult.source.features.scale,
              ) }}
            </v-chip>
          </div>
        </div>
        <div class="d-flex flex-wrap align-center justify-end ga-1">
          <v-chip size="small" variant="outlined">
            {{ audioIntelligence.evaluationResult.profile.modelName }}
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
              params: { id: audioIntelligence.evaluationResult.source.id },
              query: { backTo: 'audio-intelligence' },
            }"
            variant="text"
          />
          <v-btn
            color="primary"
            :icon="isPlaying(audioIntelligence.evaluationResult.source.id)
              ? 'mdi-pause'
              : 'mdi-play'"
            size="small"
            :title="isPlaying(audioIntelligence.evaluationResult.source.id)
              ? t('player.pause')
              : t('settings.audioSimilarityPlaySource')"
            variant="tonal"
            @click="togglePlayback(audioIntelligence.evaluationResult.source)"
          />
        </div>
      </div>

      <v-list border class="audio-similarity-results" lines="two" rounded="lg">
        <v-list-item
          v-for="match in audioIntelligence.evaluationResult.matches"
          :key="match.id"
          class="audio-similarity-result"
          :class="{
            'audio-similarity-result--active': player.currentTrack?.id === match.id,
          }"
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
                <v-chip
                  v-if="featureBpm(match.features.bpm)"
                  size="x-small"
                  variant="outlined"
                >
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
                  :title="isPlaying(match.id)
                    ? t('player.pause')
                    : t('settings.audioSimilarityPlayMatch')"
                  variant="text"
                  @click.prevent.stop="togglePlayback(match)"
                />
                <v-btn
                  :aria-pressed="match.feedback === 'relevant'"
                  :class="{
                    'audio-feedback-button--subdued':
                      match.feedback !== null && match.feedback !== 'relevant',
                  }"
                  color="success"
                  density="compact"
                  :icon="match.feedback === 'relevant'
                    ? 'mdi-thumb-up'
                    : 'mdi-thumb-up-outline'"
                  :loading="audioIntelligence.ratingTrackId === match.id"
                  size="x-small"
                  :title="t('settings.audioSimilarityRelevant')"
                  :variant="match.feedback === 'relevant' ? 'tonal' : 'text'"
                  @click.prevent.stop="toggleMatchFeedback(match.id, 'relevant')"
                />
                <v-btn
                  :aria-pressed="match.feedback === 'irrelevant'"
                  :class="{
                    'audio-feedback-button--subdued':
                      match.feedback !== null && match.feedback !== 'irrelevant',
                  }"
                  color="error"
                  density="compact"
                  :icon="match.feedback === 'irrelevant'
                    ? 'mdi-thumb-down'
                    : 'mdi-thumb-down-outline'"
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
</template>

<style scoped>
.audio-validation-controls {
  align-items: center;
  display: grid;
  gap: 12px;
  grid-template-columns: minmax(180px, 280px) auto auto;
}

.audio-expansion-title {
  align-items: center;
  display: flex;
  flex: 1;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: space-between;
  padding-right: 12px;
}

.audio-similarity-controls {
  align-items: center;
  display: grid;
  gap: 12px;
  grid-template-columns: minmax(240px, 1fr) auto auto;
}

.audio-similarity-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 16px;
}

.audio-feature-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.audio-feature-bars {
  align-items: end;
  display: grid;
  gap: 4px;
  grid-template-columns: repeat(8, minmax(0, 1fr));
  height: 72px;
}

.audio-feature-bin {
  background: rgb(var(--v-theme-primary));
  border-radius: 4px 4px 2px 2px;
  min-height: 4px;
  opacity: 0.78;
}

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
  .audio-validation-controls,
  .audio-similarity-controls {
    align-items: stretch;
    grid-template-columns: 1fr;
  }

  .audio-similarity-match-meta {
    min-width: auto;
  }
}

@media (max-width: 1100px) {
  .audio-feature-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 520px) {
  .audio-feature-grid {
    grid-template-columns: 1fr;
  }
}
</style>

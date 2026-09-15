<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import AudioSimilarityResults from '@/components/AudioSimilarityResults.vue'
import AudioValidationPoolSettings from '@/components/AudioValidationPoolSettings.vue'
import {
  type AudioSimilarityConfiguration,
  useAudioIntelligenceSettingsStore,
} from '@/stores/audioIntelligenceSettings'

const { locale, t } = useI18n()
const audioIntelligence = useAudioIntelligenceSettingsStore()

const evaluationTrackId = ref<number | null>(null)
const excludeSameAlbum = ref(true)
const excludeSameArtist = ref(true)

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

watch([excludeSameAlbum, excludeSameArtist], () => {
  audioIntelligence.evaluationResult = null
})

function percentage(value: number | null) {
  if (value === null) return '–'

  return new Intl.NumberFormat(locale.value, {
    maximumFractionDigits: 1,
    style: 'percent',
  }).format(value)
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

</script>

<template>
  <AudioValidationPoolSettings />

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

    <AudioSimilarityResults
      v-if="audioIntelligence.evaluationResult"
      :current-review-progress="currentReviewProgress ?? null"
      :exclude-same-album="excludeSameAlbum"
      :exclude-same-artist="excludeSameArtist"
      :result="audioIntelligence.evaluationResult"
    />
</template>
</template>

<style scoped>
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

@media (max-width: 760px) {
  .audio-similarity-controls {
    align-items: stretch;
    grid-template-columns: 1fr;
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

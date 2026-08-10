<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import {
  type AudioAnalyzerAccelerator,
  type AudioSimilarityTrack,
  type AudioSimilarityConfiguration,
  type AudioSimilarityRerankingSettings,
  useAudioIntelligenceSettingsStore,
} from '@/stores/audioIntelligenceSettings'
import { usePlayerStore } from '@/stores/player'
import { similarityTrackToPlayableTrack } from '@/utils/audioSimilarity'
import { formatApproximateDuration } from '@/utils/formatters'
import AudioAnalysisRunStatus from '@/components/AudioAnalysisRunStatus.vue'

const RUN_POLL_INTERVAL_MS = 5000
const ACCELERATORS: AudioAnalyzerAccelerator[] = ['cpu', 'cuda']

const { locale, t } = useI18n()
const audioIntelligence = useAudioIntelligenceSettingsStore()
const player = usePlayerStore()
const validationSampleSize = ref(200)
const expansionTarget = ref(500)
const collectionScope = ref<'all' | number>('all')
const evaluationTrackId = ref<number | null>(null)
const excludeSameAlbum = ref(true)
const excludeSameArtist = ref(true)
const rerankingEnabled = ref(false)
const tempoInfluence = ref(5)
const keyInfluence = ref(3)
const intensityInfluence = ref(4)
let runPollTimer: ReturnType<typeof setTimeout> | null = null
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
const collectionScopeItems = computed(() => [
  {
    id: 'all' as const,
    name: t('settings.audioCollectionAllRoots'),
  },
  ...audioIntelligence.settings.eligibleRoots.map(root => ({
    id: root.id,
    name: `${root.name} (${root.eligibleTrackCount})`,
  })),
])
const selectedCollectionRootId = computed(() => (
  collectionScope.value === 'all' ? null : collectionScope.value
))
const collectionRun = computed(() => (
  audioIntelligence.collectionRunForScope(selectedCollectionRootId.value)
))
const validationRun = computed(() => audioIntelligence.settings.latestValidationRun)
const analysisRunBlocksNewWork = computed(() => (
  audioIntelligence.settings.activeRun !== null
))
const analysisWorkerActive = computed(() => (
  ['fingerprinting', 'queued', 'running'].includes(
    audioIntelligence.settings.activeRun?.status ?? '',
  )
))
const benchmark = computed(() => audioIntelligence.settings.latestBenchmark)
const benchmarkActive = computed(() => (
  benchmark.value?.status === 'queued' || benchmark.value?.status === 'running'
))
const acceleratorChangeDisabled = computed(() => (
  audioIntelligence.saving || analysisWorkerActive.value || benchmarkActive.value
))
const preparedCollectionScope = computed(() => (
  collectionRun.value
    ? collectionRun.value.libraryRoot?.name
      ?? t('settings.audioCollectionAllRoots')
    : t('settings.audioCollectionAllRoots')
))
const resumableCollectionName = computed(() => (
  collectionRun.value?.libraryRoot?.name ?? t('settings.audioCollectionAllRoots')
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
watch(
  () => audioIntelligence.settings.reranking,
  (value) => {
    if (!value) return

    rerankingEnabled.value = value.enabled
    tempoInfluence.value = value.tempoInfluence
    keyInfluence.value = value.keyInfluence
    intensityInfluence.value = value.intensityInfluence
  },
  { immediate: true },
)
watch([excludeSameAlbum, excludeSameArtist], () => {
  audioIntelligence.evaluationResult = null
})
watch(
  () => audioIntelligence.settings.activeRun,
  (run) => {
    if (run?.kind !== 'collection') return

    collectionScope.value = run.libraryRoot?.id ?? 'all'
  },
  { immediate: true },
)

onMounted(async () => {
  await audioIntelligence.load()
  if (audioIntelligence.settings.enabled) {
    await audioIntelligence.loadEvaluation()
  }
})
onBeforeUnmount(stopRunPolling)

watch(
  () => [
    audioIntelligence.settings.activeRun?.status,
    audioIntelligence.settings.latestBenchmark?.status,
  ],
  ([runStatus, benchmarkStatus]) => {
    stopRunPolling()
    if (['fingerprinting', 'queued', 'running'].includes(runStatus ?? '')
      || benchmarkStatus === 'queued'
      || benchmarkStatus === 'running') {
      scheduleRunPoll()
    }
  },
  { immediate: true },
)

async function setEnabled(value: boolean | null) {
  if (value === null) return

  await audioIntelligence.save(value, validationSampleSize.value)
  if (value) {
    await audioIntelligence.loadEvaluation()
  }
}

async function saveValidationSampleSize() {
  if (!validationSampleSizeValid.value) return

  await audioIntelligence.save(
    audioIntelligence.settings.enabled,
    validationSampleSize.value,
  )
}

async function setAccelerator(value: unknown) {
  if (value !== 'cpu' && value !== 'cuda') return

  await audioIntelligence.save(
    audioIntelligence.settings.enabled,
    validationSampleSize.value,
    value,
  )
}

async function saveReranking() {
  const reranking: AudioSimilarityRerankingSettings = {
    enabled: rerankingEnabled.value,
    tempoInfluence: tempoInfluence.value,
    keyInfluence: keyInfluence.value,
    intensityInfluence: intensityInfluence.value,
  }
  await audioIntelligence.saveReranking(reranking)
  audioIntelligence.evaluationResult = null
}

async function setPersonalizationEnabled(value: boolean | null) {
  if (value === null) return

  await audioIntelligence.setPersonalizationEnabled(value)
  audioIntelligence.evaluationResult = null
}

function personalizationAdjustment(value: number) {
  const formatted = new Intl.NumberFormat(locale.value, {
    maximumFractionDigits: 2,
    signDisplay: 'always',
  }).format(value)

  return t('settings.audioPersonalizationAdjustmentValue', { value: formatted })
}

function personalizationTrainedAt(value: string | null) {
  if (!value) return null

  return new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}

function acceleratorStatus(accelerator: AudioAnalyzerAccelerator) {
  return audioIntelligence.settings.analyzerSelection.methods[accelerator]
}

function acceleratorStatusColor(accelerator: AudioAnalyzerAccelerator) {
  return {
    available: 'success',
    unavailable: 'error',
    unchecked: 'default',
  }[acceleratorStatus(accelerator)]
}

function setValidationSampleSize(value: string | number | null) {
  validationSampleSize.value = Number(value)
}

function setExpansionTarget(value: string | number | null) {
  expansionTarget.value = Number(value)
}

function prepareCollection() {
  return audioIntelligence.prepareCollection(
    collectionScope.value === 'all' ? null : collectionScope.value,
  )
}

function stopRunPolling() {
  if (runPollTimer !== null) {
    clearTimeout(runPollTimer)
    runPollTimer = null
  }
}

function scheduleRunPoll() {
  runPollTimer = setTimeout(async () => {
    await audioIntelligence.load({ silent: true })
    const runStatus = audioIntelligence.settings.activeRun?.status
    const benchmarkStatus = audioIntelligence.settings.latestBenchmark?.status
    if (['fingerprinting', 'queued', 'running'].includes(runStatus ?? '')
      || benchmarkStatus === 'queued'
      || benchmarkStatus === 'running') {
      scheduleRunPoll()
    } else if (runStatus !== undefined) {
      await audioIntelligence.loadEvaluation()
    }
  }, RUN_POLL_INTERVAL_MS)
}

function analyzerColor() {
  return audioIntelligence.settings.analyzerStatus === 'ready' ? 'success' : 'warning'
}

function benchmarkStatusColor() {
  if (benchmark.value?.status === 'completed') return 'success'
  if (benchmark.value?.status === 'failed') return 'error'
  if (benchmark.value?.status === 'partial') return 'warning'

  return 'info'
}

function benchmarkMethod(accelerator: 'cpu' | 'cuda') {
  return accelerator === 'cuda'
    ? t('settings.audioBenchmarkCuda')
    : t('settings.audioBenchmarkCpu')
}

function benchmarkRate(value: number | undefined) {
  if (value === undefined) return '–'

  return new Intl.NumberFormat(locale.value, {
    maximumFractionDigits: 2,
  }).format(value)
}

function benchmarkDuration(value: number | undefined) {
  return value === undefined
    ? '–'
    : formatApproximateDuration(value, locale.value)
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
  <v-card border class="mt-6" rounded="xl">
    <v-card-item class="pa-6 pb-2" prepend-icon="mdi-brain">
      <v-card-title>{{ t('settings.audioIntelligence') }}</v-card-title>
      <v-card-subtitle>{{ t('settings.audioIntelligenceDescription') }}</v-card-subtitle>
    </v-card-item>
    <v-card-text class="pa-6 pt-4">
      <v-alert v-if="audioIntelligence.error" class="mb-4" type="error" variant="tonal">
        {{ audioIntelligence.error }}
      </v-alert>
      <v-alert
        class="mb-5"
        icon="mdi-power-plug-off-outline"
        :text="t('settings.audioIntelligenceOptIn')"
        type="info"
        variant="tonal"
      />
      <v-skeleton-loader v-if="audioIntelligence.loading" type="list-item-two-line@2" />
      <template v-else>
        <v-switch
          color="primary"
          :disabled="audioIntelligence.saving"
          :hint="t('settings.audioIntelligenceEnabledHint')"
          :label="t('settings.audioIntelligenceEnabled')"
          :loading="audioIntelligence.saving"
          :model-value="audioIntelligence.settings.enabled"
          persistent-hint
          @update:model-value="setEnabled"
        />

        <v-divider class="my-6" />

        <section>
          <div class="text-subtitle-1 font-weight-bold">
            {{ t('settings.audioCollectionAnalysis') }}
          </div>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('settings.audioCollectionAnalysisDescription') }}
          </div>
          <div class="audio-collection-controls">
            <v-select
              v-model="collectionScope"
              class="audio-collection-scope"
              density="comfortable"
              :disabled="analysisRunBlocksNewWork"
              hide-details
              item-title="name"
              item-value="id"
              :items="collectionScopeItems"
              :label="t('settings.audioCollectionScope')"
            />
            <div class="audio-collection-actions">
              <v-tooltip location="top" :text="t('settings.prepareAudioCollectionTooltip')">
                <template #activator="{ props }">
                  <span v-bind="props" class="d-inline-flex">
                    <v-btn
                      color="primary"
                      :disabled="!audioIntelligence.settings.enabled
                        || audioIntelligence.settings.analyzerStatus !== 'ready'
                        || analyzedTrackCount === 0
                        || audioIntelligence.settings.eligibleTrackCount === 0
                        || analysisRunBlocksNewWork"
                      :loading="audioIntelligence.preparingCollection"
                      prepend-icon="mdi-database-cog-outline"
                      variant="flat"
                      @click="prepareCollection"
                    >
                      {{ t('settings.prepareAudioCollection') }}
                    </v-btn>
                  </span>
                </template>
              </v-tooltip>
              <v-tooltip
                v-if="collectionRun?.status === 'prepared'"
                location="top"
                :text="t('settings.runAudioCollectionTooltip')"
              >
                <template #activator="{ props }">
                  <span v-bind="props" class="d-inline-flex">
                    <v-btn
                      color="primary"
                      :disabled="audioIntelligence.settings.analyzerStatus !== 'ready'"
                      :loading="audioIntelligence.startingRun"
                      prepend-icon="mdi-play"
                      variant="tonal"
                      @click="audioIntelligence.startRun(collectionRun.id)"
                    >
                      {{ t('settings.runPreparedAudioCollection', {
                        scope: preparedCollectionScope,
                      }) }}
                    </v-btn>
                  </span>
                </template>
              </v-tooltip>
              <v-btn
                v-if="collectionRun
                  && ['fingerprinting', 'queued', 'running'].includes(collectionRun.status)"
                color="primary"
                :disabled="collectionRun.pauseRequestedAt !== null"
                :loading="audioIntelligence.pausingRun"
                prepend-icon="mdi-pause-circle-outline"
                variant="tonal"
                @click="audioIntelligence.pauseRun(collectionRun.id)"
              >
                {{ collectionRun.pauseRequestedAt
                  ? t('settings.audioAnalysisPausing')
                  : t('settings.pauseAudioAnalysis') }}
              </v-btn>
              <v-btn
                v-if="collectionRun
                  && ['fingerprinting', 'prepared', 'queued', 'running', 'paused'].includes(
                    collectionRun.status,
                  )"
                color="error"
                :disabled="collectionRun.cancelRequestedAt !== null"
                :loading="audioIntelligence.cancellingRun"
                prepend-icon="mdi-stop-circle-outline"
                variant="tonal"
                @click="audioIntelligence.cancelRun(collectionRun.id)"
              >
                {{ collectionRun.cancelRequestedAt
                  ? t('settings.audioAnalysisCancelling')
                  : t('settings.cancelAudioAnalysis') }}
              </v-btn>
              <v-tooltip
                v-if="collectionRun?.resumable"
                location="top"
                :text="t('settings.resumeAudioAnalysisTooltip', {
                  scope: resumableCollectionName,
                })"
              >
                <template #activator="{ props }">
                  <span v-bind="props" class="d-inline-flex">
                    <v-btn
                      color="primary"
                      :loading="audioIntelligence.resumingRun"
                      prepend-icon="mdi-restart"
                      variant="tonal"
                      @click="audioIntelligence.resumeRun(collectionRun.id)"
                    >
                      {{ t('settings.resumeScopedAudioAnalysis', {
                        scope: resumableCollectionName,
                      }) }}
                    </v-btn>
                  </span>
                </template>
              </v-tooltip>
            </div>
          </div>
          <v-alert
            v-if="!collectionRun"
            class="mt-5"
            icon="mdi-database-outline"
            :text="t('settings.audioCollectionNoRun')"
            type="info"
            variant="tonal"
          />
          <AudioAnalysisRunStatus
            v-if="collectionRun"
            class="mt-5"
            :run="collectionRun"
          />
        </section>

        <v-divider class="my-6" />

        <section>
          <div class="text-subtitle-1 font-weight-bold">
            {{ t('settings.audioSimilarityRefinement') }}
          </div>
          <div class="text-body-2 text-medium-emphasis mb-2">
            {{ t('settings.audioSimilarityRefinementDescription') }}
          </div>
          <v-switch
            v-model="rerankingEnabled"
            color="primary"
            :disabled="audioIntelligence.saving || !audioIntelligence.settings.enabled"
            :label="t('settings.audioSimilarityRefinementEnabled')"
            hide-details
          />
          <v-expand-transition>
            <div v-if="rerankingEnabled" class="audio-reranking-controls mt-3">
              <div class="audio-reranking-row">
                <div class="text-body-2">
                  {{ t('settings.audioSimilarityTempoInfluence') }}
                </div>
                <v-slider
                  v-model="tempoInfluence"
                  color="primary"
                  :disabled="audioIntelligence.saving"
                  hide-details
                  max="10"
                  min="0"
                  show-ticks="always"
                  step="1"
                  thumb-label
                />
              </div>
              <div class="audio-reranking-row">
                <div class="text-body-2">
                  {{ t('settings.audioSimilarityKeyInfluence') }}
                </div>
                <v-slider
                  v-model="keyInfluence"
                  color="primary"
                  :disabled="audioIntelligence.saving"
                  hide-details
                  max="10"
                  min="0"
                  show-ticks="always"
                  step="1"
                  thumb-label
                />
              </div>
              <div class="audio-reranking-row">
                <div class="text-body-2">
                  {{ t('settings.audioSimilarityIntensityInfluence') }}
                </div>
                <v-slider
                  v-model="intensityInfluence"
                  color="primary"
                  :disabled="audioIntelligence.saving"
                  hide-details
                  max="10"
                  min="0"
                  show-ticks="always"
                  step="1"
                  thumb-label
                />
              </div>
              <div class="text-caption text-medium-emphasis mb-3">
                {{ t('settings.audioSimilarityInfluenceHint') }}
              </div>
            </div>
          </v-expand-transition>
          <v-btn
            class="mt-3"
            color="primary"
            :disabled="!audioIntelligence.settings.enabled"
            :loading="audioIntelligence.saving"
            prepend-icon="mdi-content-save-outline"
            variant="tonal"
            @click="saveReranking"
          >
            {{ t('settings.saveAudioSimilarityRefinement') }}
          </v-btn>
        </section>

        <v-divider class="my-6" />

        <section>
          <div class="text-subtitle-1 font-weight-bold">
            {{ t('settings.audioPersonalization') }}
          </div>
          <div class="text-body-2 text-medium-emphasis mb-3">
            {{ t('settings.audioPersonalizationDescription') }}
          </div>
          <div class="d-flex flex-wrap ga-2 mb-3">
            <v-chip size="small" variant="tonal">
              {{ t('settings.audioPersonalizationFeedbackCount', {
                count: audioIntelligence.settings.personalization.feedbackCount,
                minimum: audioIntelligence.settings.personalization.minimumFeedbackCount,
              }) }}
            </v-chip>
            <v-chip color="success" size="small" variant="tonal">
              {{ t('settings.audioPersonalizationRelevantCount', {
                count: audioIntelligence.settings.personalization.relevantCount,
              }) }}
            </v-chip>
            <v-chip color="error" size="small" variant="tonal">
              {{ t('settings.audioPersonalizationIrrelevantCount', {
                count: audioIntelligence.settings.personalization.irrelevantCount,
              }) }}
            </v-chip>
          </div>
          <v-alert
            v-if="!audioIntelligence.settings.personalization.canTrain"
            class="mb-3"
            density="compact"
            :text="t('settings.audioPersonalizationNeedsFeedback', {
              total: audioIntelligence.settings.personalization.minimumFeedbackCount,
              each: audioIntelligence.settings.personalization.minimumVerdictCount,
            })"
            type="info"
            variant="tonal"
          />
          <template v-if="audioIntelligence.settings.personalization.ready">
            <div class="d-flex flex-wrap ga-2 mb-2">
              <v-chip color="primary" size="small" variant="outlined">
                {{ t('settings.audioSimilarityTempoInfluence') }}
                {{ personalizationAdjustment(
                  audioIntelligence.settings.personalization.adjustments.tempo,
                ) }}
              </v-chip>
              <v-chip color="primary" size="small" variant="outlined">
                {{ t('settings.audioSimilarityKeyInfluence') }}
                {{ personalizationAdjustment(
                  audioIntelligence.settings.personalization.adjustments.key,
                ) }}
              </v-chip>
              <v-chip color="primary" size="small" variant="outlined">
                {{ t('settings.audioSimilarityIntensityInfluence') }}
                {{ personalizationAdjustment(
                  audioIntelligence.settings.personalization.adjustments.intensity,
                ) }}
              </v-chip>
            </div>
            <div class="text-caption text-medium-emphasis mb-2">
              {{ t('settings.audioPersonalizationTrainedAt', {
                date: personalizationTrainedAt(
                  audioIntelligence.settings.personalization.trainedAt,
                ),
              }) }}
            </div>
          </template>
          <v-switch
            color="primary"
            :disabled="audioIntelligence.saving
              || !audioIntelligence.settings.personalization.ready
              || !audioIntelligence.settings.reranking.enabled"
            :hint="t('settings.audioPersonalizationEnabledHint')"
            :label="t('settings.audioPersonalizationEnabled')"
            :model-value="audioIntelligence.settings.personalization.enabled"
            persistent-hint
            @update:model-value="setPersonalizationEnabled"
          />
          <div class="d-flex flex-wrap ga-2 mt-3">
            <v-btn
              color="primary"
              :disabled="!audioIntelligence.settings.personalization.canTrain"
              :loading="audioIntelligence.trainingPersonalization"
              prepend-icon="mdi-account-cog-outline"
              variant="tonal"
              @click="audioIntelligence.trainPersonalization"
            >
              {{ audioIntelligence.settings.personalization.ready
                ? t('settings.retrainAudioPersonalization')
                : t('settings.trainAudioPersonalization') }}
            </v-btn>
            <v-btn
              v-if="audioIntelligence.settings.personalization.ready"
              :loading="audioIntelligence.resettingPersonalization"
              prepend-icon="mdi-restore"
              variant="text"
              @click="audioIntelligence.resetPersonalization"
            >
              {{ t('settings.resetAudioPersonalization') }}
            </v-btn>
          </div>
        </section>

        <v-divider class="my-6" />

        <v-expansion-panels variant="accordion">
          <v-expansion-panel>
            <v-expansion-panel-title>
              <div>
                <div class="text-subtitle-1 font-weight-bold">
                  {{ t('settings.audioAdvancedSetup') }}
                </div>
                <div class="text-body-2 text-medium-emphasis">
                  {{ t('settings.audioAdvancedSetupDescription') }}
                </div>
              </div>
            </v-expansion-panel-title>
            <v-expansion-panel-text>
        <div class="d-flex flex-wrap align-start justify-space-between ga-4">
          <div class="audio-method-copy">
            <div class="text-subtitle-1 font-weight-bold">
              {{ t('settings.audioAnalyzerMethod') }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('settings.audioAnalyzerMethodDescription') }}
            </div>
          </div>
          <v-btn-toggle
            color="primary"
            density="comfortable"
            divided
            mandatory
            :disabled="acceleratorChangeDisabled"
            :model-value="audioIntelligence.settings.analyzerSelection.selected"
            variant="outlined"
            @update:model-value="setAccelerator"
          >
            <v-btn prepend-icon="mdi-cpu-64-bit" value="cpu">
              {{ t('settings.audioBenchmarkCpu') }}
            </v-btn>
            <v-btn prepend-icon="mdi-expansion-card-variant" value="cuda">
              {{ t('settings.audioBenchmarkCuda') }}
            </v-btn>
          </v-btn-toggle>
        </div>
        <div class="d-flex flex-wrap ga-2 mt-3">
          <v-chip
            v-for="accelerator in ACCELERATORS"
            :key="accelerator"
            :color="acceleratorStatusColor(accelerator)"
            size="small"
            variant="tonal"
          >
            {{ t('settings.audioAnalyzerMethodStatus', {
              method: benchmarkMethod(accelerator),
              status: t(`settings.audioAnalyzerMethodStatuses.${acceleratorStatus(accelerator)}`),
            }) }}
          </v-chip>
          <v-chip
            v-if="audioIntelligence.settings.analyzerSelection.recommended"
            color="primary"
            prepend-icon="mdi-speedometer"
            size="small"
            variant="tonal"
          >
            {{ t('settings.audioAnalyzerMethodRecommended', {
              method: benchmarkMethod(audioIntelligence.settings.analyzerSelection.recommended),
            }) }}
          </v-chip>
        </div>
        <v-alert
          v-if="acceleratorStatus(audioIntelligence.settings.analyzerSelection.selected)
            === 'unavailable'"
          class="mt-3"
          icon="mdi-alert-outline"
          :text="t('settings.audioAnalyzerMethodUnavailable')"
          type="warning"
          variant="tonal"
        />
        <div class="text-caption text-medium-emphasis mt-3">
          {{ t('settings.audioAnalyzerMethodApplicationHint') }}
        </div>

        <v-divider class="my-6" />

        <div class="d-flex flex-wrap align-start justify-space-between ga-4">
          <div>
            <div class="text-subtitle-1 font-weight-bold">{{ t('settings.audioAnalyzer') }}</div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('settings.audioAnalyzerHint') }}
            </div>
          </div>
          <div class="d-flex align-center ga-2">
            <v-chip :color="analyzerColor()" prepend-icon="mdi-progress-wrench" variant="tonal">
              {{ t(`settings.audioAnalyzerStatuses.${audioIntelligence.settings.analyzerStatus}`) }}
            </v-chip>
            <v-btn
              :loading="audioIntelligence.testingAnalyzer"
              prepend-icon="mdi-stethoscope"
              size="small"
              variant="tonal"
              @click="audioIntelligence.testAnalyzer"
            >
              {{ t('settings.testAudioAnalyzer') }}
            </v-btn>
          </div>
        </div>
        <v-alert
          v-if="audioIntelligence.settings.analyzer.message"
          class="mt-4"
          :text="audioIntelligence.settings.analyzer.message"
          :type="audioIntelligence.settings.analyzerStatus === 'ready' ? 'success' : 'info'"
          variant="tonal"
        />
        <div v-if="audioIntelligence.settings.analyzer.profile" class="text-caption mt-3">
          {{ t('settings.audioAnalyzerProfile', {
            analyzer: audioIntelligence.settings.analyzer.profile.analyzerName,
            analyzerVersion: audioIntelligence.settings.analyzer.profile.analyzerVersion,
            model: audioIntelligence.settings.analyzer.profile.modelName,
            modelVersion: audioIntelligence.settings.analyzer.profile.modelVersion,
            dimensions: audioIntelligence.settings.analyzer.profile.embeddingDimensions,
          }) }}
        </div>
        <v-chip
          class="mt-3"
          :color="audioIntelligence.settings.vectorIndex.status === 'ready' ? 'success' : 'warning'"
          prepend-icon="mdi-vector-polyline"
          size="small"
          variant="tonal"
        >
          {{ t(`settings.audioVectorIndexStatuses.${audioIntelligence.settings.vectorIndex.status}`, {
            indexed: audioIntelligence.settings.vectorIndex.indexedArtifactCount,
            eligible: audioIntelligence.settings.vectorIndex.eligibleArtifactCount,
          }) }}
        </v-chip>

        <div class="d-flex flex-wrap align-start justify-space-between ga-3 mt-6">
          <div>
            <div class="text-subtitle-1 font-weight-bold">
              {{ t('settings.audioBenchmark') }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('settings.audioBenchmarkDescription') }}
            </div>
          </div>
          <div class="d-flex flex-wrap ga-2">
            <v-tooltip location="top" :text="t('settings.audioBenchmarkTooltip')">
              <template #activator="{ props }">
                <span v-bind="props" class="d-inline-flex">
                  <v-btn
                    color="primary"
                    :disabled="!audioIntelligence.settings.enabled
                      || analysisWorkerActive
                      || benchmarkActive"
                    :loading="audioIntelligence.startingBenchmark"
                    prepend-icon="mdi-speedometer"
                    size="small"
                    variant="tonal"
                    @click="audioIntelligence.startBenchmark"
                  >
                    {{ t('settings.runAudioBenchmark') }}
                  </v-btn>
                </span>
              </template>
            </v-tooltip>
            <v-btn
              v-if="benchmarkActive && benchmark"
              color="error"
              :disabled="benchmark.cancelRequestedAt !== null"
              :loading="audioIntelligence.cancellingBenchmark"
              prepend-icon="mdi-stop-circle-outline"
              size="small"
              variant="tonal"
              @click="audioIntelligence.cancelBenchmark(benchmark.id)"
            >
              {{ benchmark.cancelRequestedAt
                ? t('settings.audioBenchmarkCancelling')
                : t('settings.cancelAudioBenchmark') }}
            </v-btn>
          </div>
        </div>

        <v-alert
          v-if="benchmark"
          class="mt-4"
          :color="benchmarkStatusColor()"
          icon="mdi-chart-timeline-variant"
          variant="tonal"
        >
          <div class="d-flex flex-wrap align-center justify-space-between ga-2">
            <strong>
              {{ t(`settings.audioBenchmarkStatuses.${benchmark.status}`) }}
            </strong>
            <span class="text-caption">
              {{ t('settings.audioBenchmarkProgress', {
                completed: benchmark.completedConfigurationCount,
                total: benchmark.totalConfigurationCount,
              }) }}
            </span>
          </div>
          <v-progress-linear
            v-if="benchmarkActive"
            class="mt-3"
            color="primary"
            :model-value="benchmark.totalConfigurationCount > 0
              ? benchmark.completedConfigurationCount / benchmark.totalConfigurationCount * 100
              : 0"
            rounded
          />
          <div v-if="benchmark.error" class="mt-2">
            {{ benchmark.error }}
          </div>
          <div v-if="benchmark.recommendation" class="mt-3">
            {{ t('settings.audioBenchmarkRecommendation', {
              method: benchmarkMethod(benchmark.recommendation.accelerator),
              chunk: benchmark.recommendation.chunkSize,
              workers: benchmark.recommendation.preparationWorkers,
              rate: benchmarkRate(benchmark.recommendation.tracksPerMinute),
            }) }}
            <span v-if="benchmark.recommendation.speedupVsCpu !== null">
              {{ t('settings.audioBenchmarkSpeedup', {
                speedup: benchmarkRate(benchmark.recommendation.speedupVsCpu),
              }) }}
            </span>
          </div>
        </v-alert>

        <v-table v-if="benchmark?.results.length" class="mt-4" density="compact">
          <thead>
            <tr>
              <th>{{ t('settings.audioBenchmarkMethod') }}</th>
              <th>{{ t('settings.audioBenchmarkChunk') }}</th>
              <th>{{ t('settings.audioBenchmarkWorkers') }}</th>
              <th>{{ t('settings.audioBenchmarkThroughput') }}</th>
              <th>{{ t('settings.audioBenchmarkElapsed') }}</th>
              <th>{{ t('settings.audioBenchmarkVerification') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(result, index) in benchmark.results"
              :key="`${result.accelerator}-${result.preparationWorkers}-${result.chunkSize}-${index}`"
            >
              <td>{{ benchmarkMethod(result.accelerator) }}</td>
              <td>{{ result.chunkSize }}</td>
              <td>{{ result.accelerator === 'cuda' ? result.preparationWorkers : '–' }}</td>
              <td>
                <template v-if="result.status === 'completed'">
                  {{ t('settings.audioBenchmarkRate', {
                    rate: benchmarkRate(result.tracksPerMinute),
                  }) }}
                </template>
                <template v-else>
                  {{ t(`settings.audioBenchmarkResultStatuses.${result.status}`) }}
                </template>
              </td>
              <td>{{ benchmarkDuration(result.wallTimeMs) }}</td>
              <td>
                <v-icon
                  v-if="result.equivalent === true"
                  color="success"
                  icon="mdi-check-circle-outline"
                  size="small"
                />
                <v-icon
                  v-else-if="result.equivalent === false"
                  color="error"
                  icon="mdi-alert-circle-outline"
                  size="small"
                />
                <span v-else>–</span>
              </td>
            </tr>
          </tbody>
        </v-table>

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
            </v-expansion-panel-text>
          </v-expansion-panel>
        </v-expansion-panels>

        <v-divider class="my-6" />

        <v-expansion-panels variant="accordion">
          <v-expansion-panel>
            <v-expansion-panel-title>
              <div class="audio-expansion-title">
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
            </v-expansion-panel-title>
            <v-expansion-panel-text>

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
            </v-expansion-panel-text>
          </v-expansion-panel>
        </v-expansion-panels>
      </template>
    </v-card-text>
  </v-card>
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

.audio-collection-controls {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.audio-collection-scope {
  flex: 0 1 280px;
  min-width: 220px;
}

.audio-collection-actions {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.audio-reranking-controls {
  max-width: 720px;
}

.audio-reranking-row {
  align-items: center;
  display: grid;
  gap: 20px;
  grid-template-columns: 180px minmax(0, 1fr);
  min-height: 48px;
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

.audio-method-copy {
  flex: 1 1 360px;
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

  .audio-collection-controls {
    align-items: stretch;
    flex-direction: column;
  }

  .audio-collection-scope {
    flex-basis: auto;
    max-width: none;
  }

  .audio-similarity-match-meta {
    min-width: auto;
  }
}

@media (max-width: 520px) {
  .audio-reranking-row {
    gap: 2px;
    grid-template-columns: 1fr;
    padding-bottom: 8px;
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

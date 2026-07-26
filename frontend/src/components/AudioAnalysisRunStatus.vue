<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { AudioAnalysisRun } from '@/stores/audioIntelligenceSettings'
import { formatApproximateDuration, formatDateTime } from '@/utils/formatters'

const props = defineProps<{
  run: AudioAnalysisRun
}>()

const { locale, t } = useI18n()

const progress = computed(() => {
  if (!['fingerprinting', 'prepared', 'queued', 'running', 'paused'].includes(props.run.status)) {
    return null
  }

  if (props.run.phase === 'preparation') {
    const enumerating = props.run.kind === 'collection'
      && props.run.summary.candidatesEnumerated !== true

    return calculateProgress(
      enumerating
        ? props.run.summary.candidateTrackCount ?? 0
        : props.run.summary.processedFingerprintTrackCount ?? 0,
      Math.max(0, props.run.requestedTrackCount),
      enumerating
        ? t('settings.audioCollectionEnumerationProgress')
        : t('settings.audioFingerprintProgress'),
    )
  }

  return calculateProgress(
    props.run.summary.processedTrackCount ?? 0,
    props.run.selectedTrackCount,
    t('settings.audioTrackAnalysisProgress'),
  )
})

const alertType = computed(() => {
  if (props.run.status === 'failed') return 'error'
  if (['fingerprinting', 'queued', 'running', 'paused'].includes(props.run.status)) return 'info'

  return 'success'
})
const createdAtLabel = computed(() => formatDateTime(props.run.createdAt, locale.value))
const remainingTime = computed(() => {
  if (props.run.phase !== 'analysis'
    || !['queued', 'running', 'paused'].includes(props.run.status)) {
    return null
  }

  const processed = props.run.summary.processedTrackCount
    ?? props.run.summary.reusedTrackCount
    ?? 0
  const remaining = Math.max(0, props.run.selectedTrackCount - processed)
  const measured = props.run.summary.analysisMeasuredTrackCount ?? 0
  const elapsedMs = props.run.summary.analysisElapsedMs ?? 0

  if (remaining === 0) return null
  if (measured < 3 || elapsedMs <= 0) {
    return t('settings.audioAnalysisEstimatingRemaining')
  }

  return t('settings.audioAnalysisEstimatedRemaining', {
    duration: formatApproximateDuration((elapsedMs / measured) * remaining, locale.value),
  })
})

function calculateProgress(processed: number, total: number, label: string) {
  const boundedProcessed = Math.max(0, Math.min(processed, total))

  return {
    label,
    processed: boundedProcessed,
    total,
    percentage: total > 0 ? (boundedProcessed / total) * 100 : 0,
  }
}

function formattedCount(value: number) {
  return new Intl.NumberFormat(locale.value).format(value)
}

function formattedPercentage(value: number) {
  return new Intl.NumberFormat(locale.value, {
    maximumFractionDigits: 1,
  }).format(value)
}
</script>

<template>
  <v-alert
    icon="mdi-check-decagram-outline"
    :type="alertType"
    variant="tonal"
  >
    <div class="font-weight-bold">
      {{ t(`settings.audioAnalysisStatuses.${run.status}`) }}
    </div>
    <div v-if="run.kind === 'collection'" class="mb-1">
      {{ t('settings.audioCollectionRunSummary', {
        scope: run.libraryRoot?.name ?? t('settings.audioCollectionAllRoots'),
        existing: run.summary.baselineAnalyzedTrackCount ?? 0,
        total: run.requestedTrackCount,
      }) }}
    </div>
    <div v-if="run.summary.mode === 'expansion'" class="mb-1">
      {{ t('settings.audioPoolExpansionSummary', {
        existing: run.summary.baselineAnalyzedTrackCount ?? 0,
        added: run.summary.newTrackTargetCount ?? 0,
        target: run.requestedTrackCount,
      }) }}
    </div>
    <div v-if="run.phase === 'preparation' && run.status === 'fingerprinting'">
      {{ t('settings.audioAnalysisPreparationSummary', {
        processed: run.summary.processedFingerprintTrackCount ?? 0,
        candidates: run.summary.candidateTrackCount ?? 0,
        selected: run.selectedTrackCount,
        requested: run.requestedTrackCount,
        failed: run.summary.fingerprintFailedTrackCount ?? 0,
      }) }}
    </div>
    <div v-else>
      {{ t('settings.audioAnalysisSelectionSummary', {
        selected: run.selectedTrackCount,
        requested: run.requestedTrackCount,
        roots: run.summary.selectedRootCount ?? 0,
        genres: run.summary.selectedGenreCount ?? 0,
        artists: run.summary.selectedArtistCount ?? 0,
      }) }}
    </div>
    <div v-if="run.summary.analyzedTrackCount !== undefined" class="mt-1">
      {{ t('settings.audioAnalysisResultSummary', {
        analyzed: run.summary.analyzedTrackCount,
        reused: run.summary.reusedTrackCount ?? 0,
        failed: run.summary.failedTrackCount ?? 0,
        stale: run.summary.staleTrackCount ?? 0,
        selected: run.selectedTrackCount,
      }) }}
    </div>
    <div v-if="progress" class="mt-3">
      <div class="d-flex align-center justify-space-between ga-3 text-caption mb-1">
        <span>{{ progress.label }}</span>
        <span>
          {{ t('settings.audioAnalysisProgressCount', {
            processed: formattedCount(progress.processed),
            total: formattedCount(progress.total),
            percentage: formattedPercentage(progress.percentage),
          }) }}
        </span>
      </div>
      <v-progress-linear
        color="primary"
        height="8"
        :model-value="progress.percentage"
        rounded
      />
      <div
        v-if="remainingTime"
        class="d-flex align-center ga-1 text-caption text-medium-emphasis mt-1"
      >
        <v-icon icon="mdi-timer-sand" size="small" />
        <span>{{ remainingTime }}</span>
      </div>
    </div>
    <div class="text-caption mt-1">
      {{ createdAtLabel }}
    </div>
  </v-alert>
</template>

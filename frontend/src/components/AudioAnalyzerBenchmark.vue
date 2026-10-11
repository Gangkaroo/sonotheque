<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAudioIntelligenceSettingsStore } from '@/stores/audioIntelligenceSettings'
import { formatApproximateDuration } from '@/utils/formatters'

const { locale, t } = useI18n()
const audioIntelligence = useAudioIntelligenceSettingsStore()
const benchmark = computed(() => audioIntelligence.settings.latestBenchmark)
const benchmarkActive = computed(() => (
  benchmark.value?.status === 'queued' || benchmark.value?.status === 'running'
))
const analysisWorkerActive = computed(() => (
  ['fingerprinting', 'queued', 'running'].includes(
    audioIntelligence.settings.activeRun?.status ?? '',
  )
))
const recommendationApplied = computed(() => {
  const recommendation = benchmark.value?.recommendation
  const selection = audioIntelligence.settings.analyzerSelection

  return recommendation !== null && recommendation !== undefined
    && selection.tuningConfigured
    && selection.selected === recommendation.accelerator
    && selection.preparationWorkers === recommendation.preparationWorkers
    && selection.chunkSize === recommendation.chunkSize
})

async function applyRecommendation() {
  if (!benchmark.value) return

  try {
    await audioIntelligence.applyBenchmarkRecommendation(benchmark.value.id)
  } catch {
    // The settings page displays the store's error.
  }
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
</script>

<template>
  <div>
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
              <div v-if="['completed', 'partial'].includes(benchmark.status)" class="mt-3">
                <v-btn
                  color="primary"
                  :disabled="analysisWorkerActive || benchmarkActive
                    || audioIntelligence.saving || recommendationApplied"
                  :loading="audioIntelligence.saving"
                  prepend-icon="mdi-check"
                  size="small"
                  variant="tonal"
                  @click="applyRecommendation"
                >
                  {{ t(recommendationApplied
                    ? 'settings.audioBenchmarkConfigurationApplied'
                    : 'settings.applyAudioBenchmarkConfiguration') }}
                </v-btn>
                <div class="text-caption mt-2">
                  {{ t('settings.audioBenchmarkApplyHint') }}
                </div>
              </div>
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
  </div>
</template>

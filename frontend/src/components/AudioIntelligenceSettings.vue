<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import {
  type AudioAnalyzerAccelerator,
  useAudioIntelligenceSettingsStore,
} from '@/stores/audioIntelligenceSettings'
import AudioAnalyzerBenchmark from '@/components/AudioAnalyzerBenchmark.vue'
import AudioCollectionAnalysisSettings from '@/components/AudioCollectionAnalysisSettings.vue'
import AudioSimilarityPersonalizationSettings from '@/components/AudioSimilarityPersonalizationSettings.vue'
import AudioSimilarityReviewSettings from '@/components/AudioSimilarityReviewSettings.vue'

const RUN_POLL_INTERVAL_MS = 5000
const ACCELERATORS: AudioAnalyzerAccelerator[] = ['cpu', 'cuda']

const { t } = useI18n()
const audioIntelligence = useAudioIntelligenceSettingsStore()
let runPollTimer: ReturnType<typeof setTimeout> | null = null
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

  await audioIntelligence.save(value, audioIntelligence.settings.validationSampleSize)
  if (value) {
    await audioIntelligence.loadEvaluation()
  }
}

async function setAccelerator(value: unknown) {
  if (value !== 'cpu' && value !== 'cuda') return

  await audioIntelligence.save(
    audioIntelligence.settings.enabled,
    audioIntelligence.settings.validationSampleSize,
    value,
  )
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

function benchmarkMethod(accelerator: AudioAnalyzerAccelerator) {
  return accelerator === 'cuda'
    ? t('settings.audioBenchmarkCuda')
    : t('settings.audioBenchmarkCpu')
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

        <AudioCollectionAnalysisSettings />

        <v-divider class="my-6" />

        <AudioSimilarityPersonalizationSettings />

        <v-divider class="my-6" />

        <section>
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
              <AudioAnalyzerBenchmark />

              <AudioSimilarityReviewSettings />
            </v-expansion-panel-text>
          </v-expansion-panel>
        </v-expansion-panels>
      </template>
    </v-card-text>
  </v-card>
</template>

<style scoped>
.audio-method-copy {
  flex: 1 1 360px;
}

</style>

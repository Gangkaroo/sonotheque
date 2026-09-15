<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import AudioAnalysisRunStatus from '@/components/AudioAnalysisRunStatus.vue'
import { useAudioIntelligenceSettingsStore } from '@/stores/audioIntelligenceSettings'

const { t } = useI18n()
const audioIntelligence = useAudioIntelligenceSettingsStore()

const validationSampleSize = ref(200)
const expansionTarget = ref(500)
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
const analysisRunBlocksNewWork = computed(() => audioIntelligence.settings.activeRun !== null)
const resumableValidationName = computed(() => (
  validationRun.value?.kind === 'expansion'
    ? t('settings.audioPoolExpansionName')
    : t('settings.audioValidationSampleName')
))

watch(
  () => audioIntelligence.settings.validationSampleSize,
  value => {
    validationSampleSize.value = value
  },
  { immediate: true },
)

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
          || validationSampleSize !== audioIntelligence.settings.validationSampleSize"
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
        && ['fingerprinting', 'prepared', 'queued', 'running', 'paused'].includes(validationRun.status)"
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
      v-if="validationRun && ['fingerprinting', 'queued', 'running'].includes(validationRun.status)"
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
      :text="t('settings.resumeAudioAnalysisTooltip', { scope: resumableValidationName })"
    >
      <template #activator="{ props }">
        <span v-bind="props" class="d-inline-flex">
          <v-btn
            color="primary"
            :loading="audioIntelligence.resumingRun"
            prepend-icon="mdi-restart"
            variant="tonal"
            @click="audioIntelligence.resumeRun(validationRun.id)"
          >
            {{ t('settings.resumeScopedAudioAnalysis', { scope: resumableValidationName }) }}
          </v-btn>
        </span>
      </template>
    </v-tooltip>
  </div>

  <AudioAnalysisRunStatus v-if="validationRun" class="mt-5" :run="validationRun" />
</template>

<style scoped>
.audio-validation-controls {
  align-items: center;
  display: grid;
  gap: 12px;
  grid-template-columns: minmax(180px, 280px) auto auto;
}

@media (max-width: 760px) {
  .audio-validation-controls {
    align-items: stretch;
    grid-template-columns: 1fr;
  }
}
</style>

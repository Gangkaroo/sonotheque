<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import {
  type AudioSimilarityRerankingSettings,
  useAudioIntelligenceSettingsStore,
} from '@/stores/audioIntelligenceSettings'

const { locale, t } = useI18n()
const audioIntelligence = useAudioIntelligenceSettingsStore()

const rerankingEnabled = ref(false)
const tempoInfluence = ref(5)
const keyInfluence = ref(3)
const intensityInfluence = ref(4)

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
</script>

<template>
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
</template>

<style scoped>
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

@media (max-width: 520px) {
  .audio-reranking-row {
    gap: 2px;
    grid-template-columns: 1fr;
    padding-bottom: 8px;
  }
}
</style>


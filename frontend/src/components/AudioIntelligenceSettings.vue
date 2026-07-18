<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAudioIntelligenceSettingsStore } from '@/stores/audioIntelligenceSettings'
import { formatDateTime } from '@/utils/formatters'

const { locale, t } = useI18n()
const audioIntelligence = useAudioIntelligenceSettingsStore()
const sampleSize = ref(200)
let pilotPollTimer: ReturnType<typeof setTimeout> | null = null
const sampleSizeValid = computed(() => (
  Number.isInteger(sampleSize.value)
  && sampleSize.value >= 50
  && sampleSize.value <= 500
))

watch(
  () => audioIntelligence.settings.sampleSize,
  (value) => {
    sampleSize.value = value
  },
  { immediate: true },
)

onMounted(() => audioIntelligence.load())
onBeforeUnmount(stopPilotPolling)

watch(
  () => audioIntelligence.settings.latestPilot?.status,
  (status) => {
    stopPilotPolling()
    if (status === 'queued' || status === 'running') {
      schedulePilotPoll()
    }
  },
)

async function setEnabled(value: boolean | null) {
  if (value === null) return

  await audioIntelligence.save(value, sampleSize.value)
}

async function saveSampleSize() {
  if (!sampleSizeValid.value) return

  await audioIntelligence.save(audioIntelligence.settings.enabled, sampleSize.value)
}

function setSampleSize(value: string | number | null) {
  sampleSize.value = Number(value)
}

function pilotDate(value: string) {
  return formatDateTime(value, locale.value)
}

function stopPilotPolling() {
  if (pilotPollTimer !== null) {
    clearTimeout(pilotPollTimer)
    pilotPollTimer = null
  }
}

function schedulePilotPoll() {
  pilotPollTimer = setTimeout(async () => {
    await audioIntelligence.load()
    const status = audioIntelligence.settings.latestPilot?.status
    if (status === 'queued' || status === 'running') {
      schedulePilotPoll()
    }
  }, 2000)
}

function analyzerColor() {
  return audioIntelligence.settings.analyzerStatus === 'ready' ? 'success' : 'warning'
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

        <v-divider class="my-6" />

        <div class="text-subtitle-1 font-weight-bold">{{ t('settings.audioPilot') }}</div>
        <div class="text-body-2 text-medium-emphasis mb-4">
          {{ t('settings.audioPilotDescription', {
            eligible: audioIntelligence.settings.eligibleTrackCount,
          }) }}
        </div>
        <v-alert
          v-if="audioIntelligence.settings.eligibleTrackCount === 0"
          class="mb-4"
          icon="mdi-database-sync-outline"
          :text="t('settings.audioPilotNeedsFingerprints')"
          type="warning"
          variant="tonal"
        />
        <div class="audio-pilot-controls">
          <v-text-field
            density="comfortable"
            :error="!sampleSizeValid"
            :hint="t('settings.audioPilotSizeHint')"
            inputmode="numeric"
            :label="t('settings.audioPilotSize')"
            max="500"
            min="50"
            :model-value="sampleSize"
            persistent-hint
            step="50"
            type="number"
            @update:model-value="setSampleSize"
          />
          <v-btn
            :disabled="!sampleSizeValid || sampleSize === audioIntelligence.settings.sampleSize"
            :loading="audioIntelligence.saving"
            variant="tonal"
            @click="saveSampleSize"
          >
            {{ t('settings.savePilotSize') }}
          </v-btn>
          <v-btn
            color="primary"
            :disabled="!audioIntelligence.settings.enabled
              || !sampleSizeValid
              || audioIntelligence.settings.eligibleTrackCount === 0
              || sampleSize !== audioIntelligence.settings.sampleSize"
            :loading="audioIntelligence.preparingPilot"
            prepend-icon="mdi-flask-outline"
            variant="flat"
            @click="audioIntelligence.preparePilot"
          >
            {{ t('settings.prepareAudioPilot') }}
          </v-btn>
          <v-btn
            color="primary"
            :disabled="audioIntelligence.settings.latestPilot?.status !== 'prepared'
              || audioIntelligence.settings.analyzerStatus !== 'ready'"
            :loading="audioIntelligence.startingPilot"
            prepend-icon="mdi-play"
            variant="tonal"
            @click="audioIntelligence.settings.latestPilot
              && audioIntelligence.startPilot(audioIntelligence.settings.latestPilot.id)"
          >
            {{ t('settings.runAudioPilot') }}
          </v-btn>
          <v-btn
            v-if="audioIntelligence.settings.latestPilot
              && ['queued', 'running'].includes(audioIntelligence.settings.latestPilot.status)"
            color="error"
            :disabled="audioIntelligence.settings.latestPilot.cancelRequestedAt !== null"
            :loading="audioIntelligence.cancellingPilot"
            prepend-icon="mdi-stop-circle-outline"
            variant="tonal"
            @click="audioIntelligence.cancelPilot(audioIntelligence.settings.latestPilot.id)"
          >
            {{ audioIntelligence.settings.latestPilot.cancelRequestedAt
              ? t('settings.audioPilotCancelling')
              : t('settings.cancelAudioPilot') }}
          </v-btn>
          <v-btn
            v-if="audioIntelligence.settings.latestPilot?.resumable"
            color="primary"
            :loading="audioIntelligence.resumingPilot"
            prepend-icon="mdi-restart"
            variant="tonal"
            @click="audioIntelligence.resumePilot(audioIntelligence.settings.latestPilot.id)"
          >
            {{ t('settings.resumeAudioPilot') }}
          </v-btn>
        </div>

        <v-alert
          v-if="audioIntelligence.settings.latestPilot"
          class="mt-5"
          icon="mdi-check-decagram-outline"
          :type="audioIntelligence.settings.latestPilot.status === 'failed' ? 'error' : 'success'"
          variant="tonal"
        >
          <div class="font-weight-bold">
            {{ t(`settings.audioPilotStatuses.${audioIntelligence.settings.latestPilot.status}`) }}
          </div>
          <div>
            {{ t('settings.audioPilotSummary', {
              selected: audioIntelligence.settings.latestPilot.selectedTrackCount,
              requested: audioIntelligence.settings.latestPilot.requestedTrackCount,
              roots: audioIntelligence.settings.latestPilot.summary.selectedRootCount,
              genres: audioIntelligence.settings.latestPilot.summary.selectedGenreCount,
            }) }}
          </div>
          <div
            v-if="audioIntelligence.settings.latestPilot.summary.analyzedTrackCount !== undefined"
            class="mt-1"
          >
            {{ t('settings.audioPilotResultSummary', {
              analyzed: audioIntelligence.settings.latestPilot.summary.analyzedTrackCount,
              reused: audioIntelligence.settings.latestPilot.summary.reusedTrackCount ?? 0,
              failed: audioIntelligence.settings.latestPilot.summary.failedTrackCount ?? 0,
              stale: audioIntelligence.settings.latestPilot.summary.staleTrackCount ?? 0,
              selected: audioIntelligence.settings.latestPilot.selectedTrackCount,
            }) }}
          </div>
          <div class="text-caption mt-1">
            {{ pilotDate(audioIntelligence.settings.latestPilot.createdAt) }}
          </div>
        </v-alert>
      </template>
    </v-card-text>
  </v-card>
</template>

<style scoped>
.audio-pilot-controls {
  align-items: center;
  display: grid;
  gap: 12px;
  grid-template-columns: minmax(180px, 280px) auto auto;
}

@media (max-width: 760px) {
  .audio-pilot-controls {
    align-items: stretch;
    grid-template-columns: 1fr;
  }
}
</style>

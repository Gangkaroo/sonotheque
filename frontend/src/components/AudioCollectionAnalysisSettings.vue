<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import AudioAnalysisRunStatus from '@/components/AudioAnalysisRunStatus.vue'
import { useAudioIntelligenceSettingsStore } from '@/stores/audioIntelligenceSettings'

const { t } = useI18n()
const audioIntelligence = useAudioIntelligenceSettingsStore()

const collectionScope = ref<'all' | number>('all')
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
const analysisRunBlocksNewWork = computed(() => (
  audioIntelligence.settings.activeRun !== null
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

watch(
  () => audioIntelligence.settings.activeRun,
  (run) => {
    if (run?.kind !== 'collection') return

    collectionScope.value = run.libraryRoot?.id ?? 'all'
  },
  { immediate: true },
)

function prepareCollection() {
  return audioIntelligence.prepareCollection(selectedCollectionRootId.value)
}
</script>

<template>
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
</template>

<style scoped>
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

@media (max-width: 760px) {
  .audio-collection-controls {
    align-items: stretch;
    flex-direction: column;
  }

  .audio-collection-scope {
    flex-basis: auto;
    max-width: none;
  }
}
</style>


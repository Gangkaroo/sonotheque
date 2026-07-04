<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { useOnlineEnrichmentSettingsStore } from '@/stores/onlineEnrichmentSettings'

const { t } = useI18n()
const enrichment = useOnlineEnrichmentSettingsStore()
const clearCacheDialog = ref(false)

onMounted(() => enrichment.load())

async function setInformationEnabled(value: boolean | null) {
  if (value === null) return

  await enrichment.save({
    ...enrichment.settings,
    informationEnabled: value,
  })
}

async function setLyricsEnabled(value: boolean | null) {
  if (value === null) return

  await enrichment.save({
    ...enrichment.settings,
    lyricsEnabled: value,
  })
}

async function clearCache() {
  await enrichment.clearCache()
  clearCacheDialog.value = false
}

function providerResultText(provider: 'lastfm' | 'lrclib') {
  const result = enrichment.providerTests[provider]
  if (!result) return ''
  if (result.status === 'error' && result.errorCode) {
    return t(`player.enrichmentErrors.${result.errorCode}`)
  }

  return t(`settings.providerTestStatuses.${result.status}`)
}

function providerResultColor(provider: 'lastfm' | 'lrclib') {
  const status = enrichment.providerTests[provider]?.status

  return status === 'available' ? 'success' : status === 'error' ? 'error' : 'warning'
}
</script>

<template>
  <v-card border rounded="xl" class="mt-6">
    <v-card-item class="pa-6 pb-2" prepend-icon="mdi-book-music-outline">
      <v-card-title>{{ t('settings.onlineContent') }}</v-card-title>
      <v-card-subtitle>{{ t('settings.onlineContentDescription') }}</v-card-subtitle>
    </v-card-item>
    <v-card-text class="pa-6 pt-4">
      <v-alert v-if="enrichment.error" class="mb-4" type="error" variant="tonal">
        {{ enrichment.error }}
      </v-alert>
      <v-alert
        class="mb-4"
        icon="mdi-shield-check-outline"
        :text="t('settings.onlineContentPrivacy')"
        variant="tonal"
      />
      <v-skeleton-loader v-if="enrichment.loading" type="list-item-two-line@2" />
      <template v-else>
        <v-switch
          color="primary"
          :disabled="enrichment.saving"
          :hint="t('settings.onlineInformationHint')"
          :label="t('settings.onlineInformation')"
          :loading="enrichment.saving"
          :model-value="enrichment.settings.informationEnabled"
          persistent-hint
          @update:model-value="setInformationEnabled"
        />
        <v-switch
          class="mt-3"
          color="primary"
          :disabled="enrichment.saving"
          :hint="t('settings.onlineLyricsHint')"
          :label="t('settings.onlineLyrics')"
          :loading="enrichment.saving"
          :model-value="enrichment.settings.lyricsEnabled"
          persistent-hint
          @update:model-value="setLyricsEnabled"
        />

        <v-divider class="my-6" />

        <div class="text-subtitle-1 font-weight-bold">{{ t('settings.providerChecks') }}</div>
        <div class="text-body-2 text-medium-emphasis mb-4">{{ t('settings.providerChecksHint') }}</div>
        <div class="d-flex flex-wrap ga-3">
          <div class="provider-check">
            <v-btn
              prepend-icon="mdi-lan-connect"
              :loading="enrichment.testingProvider === 'lastfm'"
              variant="tonal"
              @click="enrichment.testProvider('lastfm')"
            >
              {{ t('settings.testLastFm') }}
            </v-btn>
            <v-chip
              v-if="enrichment.providerTests.lastfm"
              :color="providerResultColor('lastfm')"
              size="small"
              variant="tonal"
            >
              {{ providerResultText('lastfm') }}
            </v-chip>
          </div>
          <div class="provider-check">
            <v-btn
              prepend-icon="mdi-lan-connect"
              :loading="enrichment.testingProvider === 'lrclib'"
              variant="tonal"
              @click="enrichment.testProvider('lrclib')"
            >
              {{ t('settings.testLrclib') }}
            </v-btn>
            <v-chip
              v-if="enrichment.providerTests.lrclib"
              :color="providerResultColor('lrclib')"
              size="small"
              variant="tonal"
            >
              {{ providerResultText('lrclib') }}
            </v-chip>
          </div>
        </div>

        <v-divider class="my-6" />

        <div class="d-flex flex-wrap align-center justify-space-between ga-3">
          <div>
            <div class="text-subtitle-1 font-weight-bold">{{ t('settings.enrichmentCache') }}</div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('settings.enrichmentCacheSummary', enrichment.settings.cache) }}
            </div>
          </div>
          <v-btn
            color="error"
            :disabled="enrichment.settings.cache.total === 0"
            prepend-icon="mdi-delete-sweep-outline"
            variant="tonal"
            @click="clearCacheDialog = true"
          >
            {{ t('settings.clearEnrichmentCache') }}
          </v-btn>
        </div>
      </template>
    </v-card-text>
  </v-card>

  <v-dialog v-model="clearCacheDialog" max-width="520">
    <v-card prepend-icon="mdi-delete-sweep-outline" :title="t('settings.clearEnrichmentCache')">
      <v-card-text>{{ t('settings.clearEnrichmentCacheWarning') }}</v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="clearCacheDialog = false">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="error" :loading="enrichment.clearingCache" variant="flat" @click="clearCache">
          {{ t('settings.clearCache') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.provider-check {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
</style>

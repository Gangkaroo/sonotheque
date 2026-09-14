<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import {
  useOnlineEnrichmentStore,
  type EnrichmentErrorCode,
  type EnrichmentStatus,
} from '@/stores/onlineEnrichment'
import { openExternalUrl } from '@/utils/externalLinks'

const props = defineProps<{
  active: boolean
  albumTitle: string
  artistName: string
  trackId: number | null
}>()

const { locale, t } = useI18n()
const enrichment = useOnlineEnrichmentStore()
const artistDescriptionExpanded = ref(false)
const albumDescriptionExpanded = ref(false)
const artistDescription = computed(() => enrichment.information?.artist.data?.biography ?? '')
const albumDescription = computed(() => enrichment.information?.album.data?.summary ?? '')
const artistDescriptionIsLong = computed(() => artistDescription.value.length > 500)
const albumDescriptionIsLong = computed(() => albumDescription.value.length > 500)

watch(
  [() => props.active, () => props.trackId, () => locale.value],
  ([active, trackId, language]) => {
    if (!active || !trackId) return

    void enrichment.loadInformation(trackId, language)
    void enrichment.loadIdentity(trackId)
  },
  { immediate: true },
)

watch(
  () => props.trackId,
  () => {
    artistDescriptionExpanded.value = false
    albumDescriptionExpanded.value = false
  },
)

function enrichmentStateText(status: EnrichmentStatus | undefined, errorCode?: EnrichmentErrorCode | null) {
  if (status === 'error' && errorCode) return t(`player.enrichmentErrors.${errorCode}`)

  return status ? t(`player.enrichmentStates.${status}`) : ''
}

function identityMatchText(method?: 'search' | 'tag' | null, confidence?: number | null) {
  if (method === 'tag') return t('player.matchFromTags')
  if (method === 'search' && confidence) return t('player.matchFromSearch', { confidence })

  return null
}

function activePeriod(from?: string | null, to?: string | null) {
  if (!from && !to) return null

  return `${from ?? '?'} - ${to ?? t('player.present')}`
}
</script>

<template>
  <div class="now-playing-tab-content pa-4">
    <v-skeleton-loader
      v-if="enrichment.informationLoading && enrichment.identityLoading"
      type="article@2"
    />
    <v-alert
      v-if="enrichment.informationError"
      class="mb-4"
      type="error"
      variant="tonal"
    >
      {{ enrichment.informationError }}
    </v-alert>
    <v-alert
      v-if="enrichment.identityError"
      class="mb-4"
      type="warning"
      variant="tonal"
    >
      {{ enrichment.identityError }}
    </v-alert>

    <template v-if="!enrichment.informationLoading || !enrichment.identityLoading">
      <v-alert
        v-if="
          enrichment.information?.artist.stale
            || enrichment.information?.album.stale
            || enrichment.identity?.artist.stale
            || enrichment.identity?.album.stale
        "
        class="mb-4"
        icon="mdi-cached"
        :text="t('player.enrichmentStale')"
        type="info"
        variant="tonal"
      />
      <v-alert
        v-if="enrichment.information?.artist.status === 'disabled'"
        class="mb-4"
        icon="mdi-cloud-off-outline"
        :text="t('player.enrichmentDisabledDescription')"
        :title="t('player.enrichmentDisabledTitle')"
        variant="tonal"
      />

      <v-card class="mb-4" rounded="lg" variant="outlined">
        <v-card-item prepend-icon="mdi-account-music-outline">
          <v-card-title>{{ t('player.artistInformation') }}</v-card-title>
          <v-card-subtitle>
            {{ enrichment.identity?.artist.data?.name ?? enrichment.information?.artist.data?.name ?? artistName }}
          </v-card-subtitle>
        </v-card-item>
        <v-card-text>
          <v-progress-linear
            v-if="enrichment.identityLoading"
            class="mb-4"
            color="primary"
            indeterminate
          />
          <div
            v-if="enrichment.identity?.artist.status === 'ready'"
            class="d-flex flex-wrap align-center ga-2 mb-4"
          >
            <v-chip
              v-if="identityMatchText(
                enrichment.identity.artist.data?.matchMethod,
                enrichment.identity.artist.data?.matchConfidence,
              )"
              prepend-icon="mdi-check-decagram-outline"
              size="small"
              variant="tonal"
            >
              {{ identityMatchText(
                enrichment.identity.artist.data?.matchMethod,
                enrichment.identity.artist.data?.matchConfidence,
              ) }}
            </v-chip>
            <v-chip v-if="enrichment.identity.artist.data?.country" size="small" variant="outlined">
              {{ t('player.country') }}: {{ enrichment.identity.artist.data.country }}
            </v-chip>
            <v-chip
              v-if="activePeriod(
                enrichment.identity.artist.data?.activeFrom,
                enrichment.identity.artist.data?.activeTo,
              )"
              size="small"
              variant="outlined"
            >
              {{ t('player.activePeriod') }}:
              {{ activePeriod(
                enrichment.identity.artist.data?.activeFrom,
                enrichment.identity.artist.data?.activeTo,
              ) }}
            </v-chip>
            <v-btn
              v-if="enrichment.identity.artist.data?.attribution.sourceUrl"
              append-icon="mdi-open-in-new"
              size="small"
              variant="text"
              @click="openExternalUrl(enrichment.identity.artist.data.attribution.sourceUrl)"
            >
              MusicBrainz
            </v-btn>
          </div>
          <v-alert
            v-else-if="enrichment.identity?.artist.status === 'ambiguous'"
            class="mb-4"
            density="compact"
            type="warning"
            variant="tonal"
          >
            {{ t('player.enrichmentStates.ambiguous') }}
          </v-alert>
          <template v-if="artistDescription">
            <p
              class="enrichment-copy"
              :class="{ 'enrichment-copy--collapsed': artistDescriptionIsLong && !artistDescriptionExpanded }"
            >
              {{ artistDescription }}
            </p>
            <v-btn
              v-if="artistDescriptionIsLong"
              class="mt-1 px-0"
              :append-icon="artistDescriptionExpanded ? 'mdi-chevron-up' : 'mdi-chevron-down'"
              size="small"
              variant="text"
              @click="artistDescriptionExpanded = !artistDescriptionExpanded"
            >
              {{ artistDescriptionExpanded ? t('player.showLess') : t('player.showMore') }}
            </v-btn>
          </template>
          <div v-if="enrichment.information?.artist.data?.tags.length" class="d-flex flex-wrap ga-2 mt-4">
            <v-chip v-for="tag in enrichment.information?.artist.data?.tags ?? []" :key="tag" size="small" variant="tonal">
              {{ tag }}
            </v-chip>
          </div>
          <v-btn
            v-if="enrichment.information?.artist.data?.attribution.sourceUrl"
            class="mt-4 px-0"
            append-icon="mdi-open-in-new"
            variant="text"
            @click="openExternalUrl(enrichment.information.artist.data.attribution.sourceUrl)"
          >
            {{ t('player.source', { source: enrichment.information.artist.data.attribution.label }) }}
          </v-btn>
          <div
            v-else-if="!enrichment.informationLoading && enrichment.information?.artist.status !== 'ready'"
            class="text-medium-emphasis"
          >
            {{ enrichmentStateText(
              enrichment.information?.artist.status,
              enrichment.information?.artist.errorCode,
            ) }}
          </div>
        </v-card-text>
      </v-card>

      <v-card rounded="lg" variant="outlined">
        <v-card-item prepend-icon="mdi-album">
          <v-card-title>{{ t('player.albumInformation') }}</v-card-title>
          <v-card-subtitle>
            {{ enrichment.identity?.album.data?.title ?? enrichment.information?.album.data?.title ?? albumTitle }}
          </v-card-subtitle>
        </v-card-item>
        <v-card-text>
          <v-progress-linear
            v-if="enrichment.identityLoading"
            class="mb-4"
            color="primary"
            indeterminate
          />
          <div
            v-if="enrichment.identity?.album.status === 'ready'"
            class="d-flex flex-wrap align-center ga-2 mb-4"
          >
            <v-chip
              v-if="identityMatchText(
                enrichment.identity.album.data?.matchMethod,
                enrichment.identity.album.data?.matchConfidence,
              )"
              prepend-icon="mdi-check-decagram-outline"
              size="small"
              variant="tonal"
            >
              {{ identityMatchText(
                enrichment.identity.album.data?.matchMethod,
                enrichment.identity.album.data?.matchConfidence,
              ) }}
            </v-chip>
            <v-chip v-if="enrichment.identity.album.data?.releaseDate" size="small" variant="outlined">
              {{ t('player.releaseDate') }}: {{ enrichment.identity.album.data.releaseDate }}
            </v-chip>
            <v-chip v-if="enrichment.identity.album.data?.label" size="small" variant="outlined">
              {{ t('player.label') }}: {{ enrichment.identity.album.data.label }}
            </v-chip>
            <v-chip v-if="enrichment.identity.album.data?.releaseType" size="small" variant="outlined">
              {{ t('player.releaseType') }}: {{ enrichment.identity.album.data.releaseType }}
            </v-chip>
            <v-btn
              v-if="enrichment.identity.album.data?.attribution.sourceUrl"
              append-icon="mdi-open-in-new"
              size="small"
              variant="text"
              @click="openExternalUrl(enrichment.identity.album.data.attribution.sourceUrl)"
            >
              MusicBrainz
            </v-btn>
          </div>
          <v-alert
            v-else-if="enrichment.identity?.album.status === 'ambiguous'"
            class="mb-4"
            density="compact"
            type="warning"
            variant="tonal"
          >
            {{ t('player.enrichmentStates.ambiguous') }}
          </v-alert>
          <template v-if="albumDescription">
            <p
              class="enrichment-copy"
              :class="{ 'enrichment-copy--collapsed': albumDescriptionIsLong && !albumDescriptionExpanded }"
            >
              {{ albumDescription }}
            </p>
            <v-btn
              v-if="albumDescriptionIsLong"
              class="mt-1 px-0"
              :append-icon="albumDescriptionExpanded ? 'mdi-chevron-up' : 'mdi-chevron-down'"
              size="small"
              variant="text"
              @click="albumDescriptionExpanded = !albumDescriptionExpanded"
            >
              {{ albumDescriptionExpanded ? t('player.showLess') : t('player.showMore') }}
            </v-btn>
          </template>
          <div v-if="enrichment.information?.album.data?.tags.length" class="d-flex flex-wrap ga-2 mt-4">
            <v-chip v-for="tag in enrichment.information?.album.data?.tags ?? []" :key="tag" size="small" variant="tonal">
              {{ tag }}
            </v-chip>
          </div>
          <v-btn
            v-if="enrichment.information?.album.data?.attribution.sourceUrl"
            class="mt-4 px-0"
            append-icon="mdi-open-in-new"
            variant="text"
            @click="openExternalUrl(enrichment.information.album.data.attribution.sourceUrl)"
          >
            {{ t('player.source', { source: enrichment.information.album.data.attribution.label }) }}
          </v-btn>
          <div
            v-else-if="!enrichment.informationLoading && enrichment.information?.album.status !== 'ready'"
            class="text-medium-emphasis"
          >
            {{ enrichmentStateText(
              enrichment.information?.album.status,
              enrichment.information?.album.errorCode,
            ) }}
          </div>
        </v-card-text>
      </v-card>
    </template>
  </div>
</template>

<style scoped>
.now-playing-tab-content {
  max-width: 100%;
}

.enrichment-copy {
  line-height: 1.65;
  white-space: pre-wrap;
}

.enrichment-copy--collapsed {
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 8;
  display: -webkit-box;
  overflow: hidden;
}
</style>

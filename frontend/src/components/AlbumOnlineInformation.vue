<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'
import type {
  AlbumInformation,
  ArtistInformation,
  EnrichmentErrorCode,
  EnrichmentResult,
  EnrichmentStatus,
  TrackIdentity,
  TrackInformation,
} from '@/stores/onlineEnrichment'
import { openExternalUrl } from '@/utils/externalLinks'

const props = defineProps<{ trackId: number }>()
const { locale, t } = useI18n()
const activeTab = ref<'album' | 'artist'>('album')
const identity = ref<TrackIdentity | null>(null)
const information = ref<TrackInformation | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const albumExpanded = ref(false)
const artistExpanded = ref(false)
let requestId = 0

const isVisible = computed(() => {
  if (loading.value || error.value) return true

  return [
    identity.value?.album.status,
    identity.value?.artist.status,
    information.value?.album.status,
    information.value?.artist.status,
  ].some((status) => status && !['disabled', 'not_configured', 'not_found'].includes(status))
})
const albumDescription = computed(() => information.value?.album.data?.summary ?? '')
const artistDescription = computed(() => information.value?.artist.data?.biography ?? '')
const albumDescriptionIsLong = computed(() => albumDescription.value.length > 500)
const artistDescriptionIsLong = computed(() => artistDescription.value.length > 500)

watch(
  [() => props.trackId, () => locale.value],
  ([trackId, language]) => void load(trackId, language),
  { immediate: true },
)

async function load(trackId: number, language: string) {
  const request = ++requestId
  loading.value = true
  error.value = null
  albumExpanded.value = false
  artistExpanded.value = false

  try {
    const [nextIdentity, nextInformation] = await Promise.all([
      apiRequest<TrackIdentity>(`/enrichment/tracks/${trackId}/identity`),
      apiRequest<TrackInformation>(
        `/enrichment/tracks/${trackId}/information?language=${encodeURIComponent(language)}`,
      ),
    ])
    if (request !== requestId) return

    identity.value = nextIdentity
    information.value = nextInformation
  } catch (cause) {
    if (request === requestId) {
      error.value = cause instanceof Error ? cause.message : t('player.enrichmentLoadFailed')
    }
  } finally {
    if (request === requestId) loading.value = false
  }
}

function stateText(status?: EnrichmentStatus, errorCode?: EnrichmentErrorCode | null) {
  if (status === 'error' && errorCode) return t(`player.enrichmentErrors.${errorCode}`)

  return status ? t(`player.enrichmentStates.${status}`) : t('player.enrichmentStates.not_found')
}

function matchText(data?: ArtistInformation | AlbumInformation | null) {
  if (data?.matchMethod === 'tag') return t('player.matchFromTags')
  if (data?.matchMethod === 'search' && data.matchConfidence) {
    return t('player.matchFromSearch', { confidence: data.matchConfidence })
  }

  return null
}

function activePeriod(data?: ArtistInformation | null) {
  if (!data?.activeFrom && !data?.activeTo) return null

  return `${data.activeFrom ?? '?'} - ${data.activeTo ?? t('player.present')}`
}

function ready<T>(result?: EnrichmentResult<T>): result is EnrichmentResult<T> & { data: T } {
  return result?.status === 'ready' && result.data !== null && result.data !== undefined
}
</script>

<template>
  <v-card v-if="isVisible" border class="mb-8" rounded="xl">
    <v-card-item prepend-icon="mdi-information-slab-circle-outline">
      <v-card-title>{{ t('albums.onlineInformation') }}</v-card-title>
      <v-card-subtitle>{{ t('albums.onlineInformationDescription') }}</v-card-subtitle>
    </v-card-item>

    <v-tabs v-model="activeTab" color="primary" grow>
      <v-tab prepend-icon="mdi-album" value="album">{{ t('player.albumInformation') }}</v-tab>
      <v-tab prepend-icon="mdi-account-music-outline" value="artist">{{ t('player.artistInformation') }}</v-tab>
    </v-tabs>
    <v-divider />

    <v-card-text v-if="loading">
      <v-skeleton-loader type="article" />
    </v-card-text>
    <v-card-text v-else-if="error">
      <v-alert type="error" variant="tonal">{{ error }}</v-alert>
    </v-card-text>
    <v-window v-else v-model="activeTab">
      <v-window-item value="album">
        <v-card-text>
          <v-alert
            v-if="identity?.album.stale || information?.album.stale"
            class="mb-4"
            density="compact"
            icon="mdi-cached"
            :text="t('player.enrichmentStale')"
            type="info"
            variant="tonal"
          />
          <div v-if="ready(identity?.album)" class="d-flex flex-wrap align-center ga-2 mb-4">
            <v-chip v-if="matchText(identity.album.data)" prepend-icon="mdi-check-decagram-outline" size="small" variant="tonal">
              {{ matchText(identity.album.data) }}
            </v-chip>
            <v-chip v-if="identity.album.data.releaseDate" size="small" variant="outlined">
              {{ t('player.releaseDate') }}: {{ identity.album.data.releaseDate }}
            </v-chip>
            <v-chip v-if="identity.album.data.label" size="small" variant="outlined">
              {{ t('player.label') }}: {{ identity.album.data.label }}
            </v-chip>
            <v-chip v-if="identity.album.data.releaseType" size="small" variant="outlined">
              {{ t('player.releaseType') }}: {{ identity.album.data.releaseType }}
            </v-chip>
            <v-btn
              v-if="identity.album.data.attribution.sourceUrl"
              append-icon="mdi-open-in-new"
              size="small"
              variant="text"
              @click="openExternalUrl(identity.album.data.attribution.sourceUrl)"
            >
              MusicBrainz
            </v-btn>
          </div>
          <v-alert v-else-if="identity?.album.status === 'ambiguous'" class="mb-4" density="compact" type="warning" variant="tonal">
            {{ stateText(identity.album.status) }}
          </v-alert>

          <template v-if="albumDescription">
            <p class="online-information-copy" :class="{ 'online-information-copy--collapsed': albumDescriptionIsLong && !albumExpanded }">
              {{ albumDescription }}
            </p>
            <v-btn
              v-if="albumDescriptionIsLong"
              class="mt-1 px-0"
              :append-icon="albumExpanded ? 'mdi-chevron-up' : 'mdi-chevron-down'"
              size="small"
              variant="text"
              @click="albumExpanded = !albumExpanded"
            >
              {{ albumExpanded ? t('player.showLess') : t('player.showMore') }}
            </v-btn>
          </template>
          <div v-if="ready(information?.album) && information.album.data.tags.length" class="d-flex flex-wrap ga-2 mt-4">
            <v-chip v-for="tag in information.album.data.tags" :key="tag" size="small" variant="tonal">{{ tag }}</v-chip>
          </div>
          <v-btn
            v-if="ready(information?.album) && information.album.data.attribution.sourceUrl"
            class="mt-4 px-0"
            append-icon="mdi-open-in-new"
            variant="text"
            @click="openExternalUrl(information.album.data.attribution.sourceUrl)"
          >
            {{ t('player.source', { source: information.album.data.attribution.label }) }}
          </v-btn>
          <div v-else-if="!ready(identity?.album) && !ready(information?.album)" class="text-medium-emphasis">
            {{ stateText(information?.album.status ?? identity?.album.status, information?.album.errorCode ?? identity?.album.errorCode) }}
          </div>
        </v-card-text>
      </v-window-item>

      <v-window-item value="artist">
        <v-card-text>
          <v-alert
            v-if="identity?.artist.stale || information?.artist.stale"
            class="mb-4"
            density="compact"
            icon="mdi-cached"
            :text="t('player.enrichmentStale')"
            type="info"
            variant="tonal"
          />
          <div v-if="ready(identity?.artist)" class="d-flex flex-wrap align-center ga-2 mb-4">
            <v-chip v-if="matchText(identity.artist.data)" prepend-icon="mdi-check-decagram-outline" size="small" variant="tonal">
              {{ matchText(identity.artist.data) }}
            </v-chip>
            <v-chip v-if="identity.artist.data.country" size="small" variant="outlined">
              {{ t('player.country') }}: {{ identity.artist.data.country }}
            </v-chip>
            <v-chip v-if="activePeriod(identity.artist.data)" size="small" variant="outlined">
              {{ t('player.activePeriod') }}: {{ activePeriod(identity.artist.data) }}
            </v-chip>
            <v-btn
              v-if="identity.artist.data.attribution.sourceUrl"
              append-icon="mdi-open-in-new"
              size="small"
              variant="text"
              @click="openExternalUrl(identity.artist.data.attribution.sourceUrl)"
            >
              MusicBrainz
            </v-btn>
          </div>
          <v-alert v-else-if="identity?.artist.status === 'ambiguous'" class="mb-4" density="compact" type="warning" variant="tonal">
            {{ stateText(identity.artist.status) }}
          </v-alert>

          <template v-if="artistDescription">
            <p class="online-information-copy" :class="{ 'online-information-copy--collapsed': artistDescriptionIsLong && !artistExpanded }">
              {{ artistDescription }}
            </p>
            <v-btn
              v-if="artistDescriptionIsLong"
              class="mt-1 px-0"
              :append-icon="artistExpanded ? 'mdi-chevron-up' : 'mdi-chevron-down'"
              size="small"
              variant="text"
              @click="artistExpanded = !artistExpanded"
            >
              {{ artistExpanded ? t('player.showLess') : t('player.showMore') }}
            </v-btn>
          </template>
          <div v-if="ready(information?.artist) && information.artist.data.tags.length" class="d-flex flex-wrap ga-2 mt-4">
            <v-chip v-for="tag in information.artist.data.tags" :key="tag" size="small" variant="tonal">{{ tag }}</v-chip>
          </div>
          <v-btn
            v-if="ready(information?.artist) && information.artist.data.attribution.sourceUrl"
            class="mt-4 px-0"
            append-icon="mdi-open-in-new"
            variant="text"
            @click="openExternalUrl(information.artist.data.attribution.sourceUrl)"
          >
            {{ t('player.source', { source: information.artist.data.attribution.label }) }}
          </v-btn>
          <div v-else-if="!ready(identity?.artist) && !ready(information?.artist)" class="text-medium-emphasis">
            {{ stateText(information?.artist.status ?? identity?.artist.status, information?.artist.errorCode ?? identity?.artist.errorCode) }}
          </div>
        </v-card-text>
      </v-window-item>
    </v-window>
  </v-card>
</template>

<style scoped>
.online-information-copy {
  line-height: 1.65;
  margin: 0;
  white-space: pre-wrap;
}

.online-information-copy--collapsed {
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 8;
  display: -webkit-box;
  overflow: hidden;
}
</style>

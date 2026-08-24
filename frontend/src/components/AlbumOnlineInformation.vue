<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'
import type {
  AlbumInformation,
  ArtistInformation,
  ArtistImageInformation,
  EnrichmentErrorCode,
  EnrichmentResult,
  EnrichmentStatus,
  TrackIdentity,
  TrackInformation,
} from '@/stores/onlineEnrichment'
import { openExternalUrl } from '@/utils/externalLinks'
import type { MusicBrainzReleaseCandidate } from '@/types/musicianCredits'

import MusicBrainzReleaseChooser from './MusicBrainzReleaseChooser.vue'
import MusicianCreditsEditor from './MusicianCreditsEditor.vue'

const props = withDefaults(defineProps<{
  trackId: number
  albumId?: number
  content?: 'all' | 'artist'
}>(), {
  content: 'all',
})
const emit = defineEmits<{
  artistImage: [url: string | null]
  artistCountry: [country: string | null]
}>()
const { locale, t } = useI18n()
const activeTab = ref<'album' | 'artist' | 'musicians'>(props.content === 'artist' ? 'artist' : 'album')
const identity = ref<TrackIdentity | null>(null)
const information = ref<TrackInformation | null>(null)
const artistImage = ref<EnrichmentResult<ArtistImageInformation> | null>(null)
const musicians = ref<AlbumMusicianResult | null>(null)
const loading = ref(false)
const musiciansLoading = ref(false)
const musicianReleaseDialog = ref(false)
const selectedMusicianReleaseId = ref<string | null>(null)
const resolvingMusicianRelease = ref(false)
const musicianReleaseError = ref<string | null>(null)
const error = ref<string | null>(null)
const albumExpanded = ref(false)
const artistExpanded = ref(false)
let requestId = 0
let musiciansPoll: ReturnType<typeof setTimeout> | null = null

interface MusicianCreditTrack {
  id: number
  title: string
  discNumber?: number | null
  trackNumber?: number | null
}

interface MusicianCredit {
  provider?: string
  manual?: boolean
  relationshipType: string
  role: string
  creditedAs?: string | null
  guest: boolean
  additional: boolean
  scope: 'recording' | 'release'
  tracks: MusicianCreditTrack[]
}

interface AlbumMusician {
  id: number
  name: string
  sortName?: string | null
  disambiguation?: string | null
  entityType?: string | null
  credits: MusicianCredit[]
}

interface AlbumMusicianResult {
  status: EnrichmentStatus
  provider: string
  lookupVersion: number
  data?: {
    providerStatus?: EnrichmentStatus | 'disabled'
    releaseId?: string | null
    selectedReleaseId?: string | null
    candidateReleases: MusicBrainzReleaseCandidate[]
    sourceUrl?: string | null
    fetchedAt?: string | null
    musicians: AlbumMusician[]
  } | null
  errorCode?: EnrichmentErrorCode | null
}

const isVisible = computed(() => {
  if (loading.value || error.value) return true

  const statuses = props.content === 'artist'
    ? [identity.value?.artist.status, information.value?.artist.status]
    : [
        identity.value?.album.status,
        identity.value?.artist.status,
        information.value?.album.status,
        information.value?.artist.status,
        musicians.value?.status,
      ]

  return statuses.some((status) => status && !['disabled', 'not_configured', 'not_found'].includes(status))
})
const albumDescription = computed(() => information.value?.album.data?.summary ?? '')
const artistDescription = computed(() => information.value?.artist.data?.biography ?? '')
const albumDescriptionIsLong = computed(() => albumDescription.value.length > 500)
const artistDescriptionIsLong = computed(() => artistDescription.value.length > 500)
const musicianReleaseCandidates = computed(() => musicians.value?.data?.candidateReleases ?? [])

watch(
  [() => props.trackId, () => props.albumId, () => locale.value],
  ([trackId, albumId, language]) => void load(trackId, albumId, language),
  { immediate: true },
)

onUnmounted(clearMusiciansPoll)

watch(() => props.content, (content) => {
  activeTab.value = content === 'artist' ? 'artist' : 'album'
})

async function load(trackId: number, albumId: number | undefined, language: string) {
  const request = ++requestId
  clearMusiciansPoll()
  loading.value = true
  error.value = null
  artistImage.value = null
  musicians.value = null
  musicianReleaseDialog.value = false
  musicianReleaseError.value = null
  emit('artistImage', null)
  emit('artistCountry', null)
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
    emit(
      'artistCountry',
      ready(nextIdentity.artist) ? nextIdentity.artist.data.country ?? null : null,
    )
    if (props.content === 'artist') void loadArtistImage(trackId, request)
    if (props.content === 'all' && albumId) void loadMusicians(albumId, request, true)
  } catch (cause) {
    if (request === requestId) {
      error.value = cause instanceof Error ? cause.message : t('player.enrichmentLoadFailed')
    }
  } finally {
    if (request === requestId) loading.value = false
  }
}

async function loadMusicians(albumId: number, request: number, initial = false) {
  if (initial) musiciansLoading.value = true

  try {
    const result = await apiRequest<AlbumMusicianResult>(`/enrichment/albums/${albumId}/musicians`)
    if (request !== requestId) return

    musicians.value = result
    if (result.status === 'pending' || result.data?.providerStatus === 'pending') {
      musiciansPoll = setTimeout(() => void loadMusicians(albumId, request), 5000)
    }
  } catch (cause) {
    if (request === requestId) {
      musicians.value = {
        status: 'error',
        provider: 'musicbrainz',
        lookupVersion: 4,
        data: null,
        errorCode: cause instanceof Error ? 'provider_error' : 'connection',
      }
    }
  } finally {
    if (request === requestId && initial) musiciansLoading.value = false
  }
}

function reloadMusicians() {
  clearMusiciansPoll()
  if (props.albumId) void loadMusicians(props.albumId, requestId, true)
}

function clearMusiciansPoll() {
  if (musiciansPoll !== null) clearTimeout(musiciansPoll)
  musiciansPoll = null
}

async function loadArtistImage(trackId: number, request: number) {
  try {
    const result = await apiRequest<EnrichmentResult<ArtistImageInformation>>(
      `/enrichment/tracks/${trackId}/artist-image-information`,
    )
    if (request !== requestId) return

    artistImage.value = result
    emit('artistImage', ready(result) ? result.data.imageUrl : null)
  } catch {
    if (request === requestId) emit('artistImage', null)
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

function ready<T>(result?: EnrichmentResult<T> | null): result is EnrichmentResult<T> & { data: T } {
  return result?.status === 'ready' && result.data !== null && result.data !== undefined
}

function musicianCreditLabel(credit: MusicianCredit) {
  const modifiers = [
    credit.guest ? t('albums.guestMusician') : null,
    credit.additional ? t('albums.additionalMusician') : null,
  ].filter(Boolean)

  return modifiers.length ? `${credit.role} (${modifiers.join(', ')})` : credit.role
}

function musicianCreditProvider(credit: MusicianCredit) {
  if (credit.manual) return t('albums.manualMusicianCredit')
  if (credit.provider === 'discogs') return 'Discogs'
  return 'MusicBrainz'
}

function musicianTrackScope(credit: MusicianCredit) {
  if (credit.scope === 'release') return t('albums.albumWideCredit')
  if (!credit.tracks.length) return t('albums.recordingCredit')

  return credit.tracks
    .map((track) => {
      const number = [track.discNumber, track.trackNumber].filter((value) => value !== null && value !== undefined).join('.')
      return number ? `${number} ${track.title}` : track.title
    })
    .join(', ')
}

function openMusicianReleaseDialog() {
  selectedMusicianReleaseId.value = musicians.value?.data?.selectedReleaseId
    ?? musicianReleaseCandidates.value[0]?.id
    ?? null
  musicianReleaseError.value = null
  musicianReleaseDialog.value = true
}

async function resolveMusicianRelease() {
  if (!props.albumId || !selectedMusicianReleaseId.value) return

  resolvingMusicianRelease.value = true
  musicianReleaseError.value = null
  clearMusiciansPoll()
  try {
    const result = await apiRequest<AlbumMusicianResult>(
      `/enrichment/albums/${props.albumId}/musicians/release`,
      {
        method: 'PUT',
        body: JSON.stringify({ releaseId: selectedMusicianReleaseId.value }),
      },
    )
    musicians.value = result
    musicianReleaseDialog.value = false
    if (result.status === 'pending') {
      musiciansPoll = setTimeout(() => void loadMusicians(props.albumId!, requestId), 1000)
    }
  } catch (cause) {
    musicianReleaseError.value = cause instanceof Error
      ? cause.message
      : t('albums.musicianReleaseResolveFailed')
  } finally {
    resolvingMusicianRelease.value = false
  }
}
</script>

<template>
  <v-card v-if="isVisible" border class="mb-8" rounded="xl">
    <v-card-item prepend-icon="mdi-information-slab-circle-outline">
      <v-card-title>{{ t('albums.onlineInformation') }}</v-card-title>
      <v-card-subtitle>
        {{ props.content === 'artist' ? t('artists.onlineInformationDescription') : t('albums.onlineInformationDescription') }}
      </v-card-subtitle>
    </v-card-item>

    <v-tabs v-if="props.content === 'all'" v-model="activeTab" color="primary" grow>
      <v-tab prepend-icon="mdi-album" value="album">{{ t('player.albumInformation') }}</v-tab>
      <v-tab prepend-icon="mdi-account-music-outline" value="artist">{{ t('player.artistInformation') }}</v-tab>
      <v-tab prepend-icon="mdi-account-group-outline" value="musicians">{{ t('albums.musicians') }}</v-tab>
    </v-tabs>
    <v-divider v-if="props.content === 'all'" />

    <v-card-text v-if="loading">
      <v-skeleton-loader type="article" />
    </v-card-text>
    <v-card-text v-else-if="error">
      <v-alert type="error" variant="tonal">{{ error }}</v-alert>
    </v-card-text>
    <v-window v-else v-model="activeTab">
      <v-window-item v-if="props.content === 'all'" value="album">
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
          <div v-if="ready(artistImage)" class="artist-image-attribution d-flex flex-wrap align-center ga-2 mt-4">
            <v-icon icon="mdi-camera-outline" size="small" />
            <span class="text-caption">
              {{ t('artists.photoCredit', { author: artistImage.data.author ?? artistImage.data.attribution.label }) }}
            </span>
            <v-btn
              v-if="artistImage.data.attribution.sourceUrl"
              append-icon="mdi-open-in-new"
              size="small"
              variant="text"
              @click="openExternalUrl(artistImage.data.attribution.sourceUrl)"
            >
              {{ artistImage.data.attribution.label }}
            </v-btn>
            <v-btn
              v-if="artistImage.data.licenseName && artistImage.data.licenseUrl"
              append-icon="mdi-open-in-new"
              size="small"
              variant="text"
              @click="openExternalUrl(artistImage.data.licenseUrl)"
            >
              {{ artistImage.data.licenseName }}
            </v-btn>
          </div>
          <div v-else-if="!ready(identity?.artist) && !ready(information?.artist)" class="text-medium-emphasis">
            {{ stateText(information?.artist.status ?? identity?.artist.status, information?.artist.errorCode ?? identity?.artist.errorCode) }}
          </div>
        </v-card-text>
      </v-window-item>

      <v-window-item v-if="props.content === 'all'" value="musicians">
        <div v-if="props.albumId" class="d-flex justify-end px-4 pt-4">
          <MusicianCreditsEditor :album-id="props.albumId" @updated="reloadMusicians" />
        </div>
        <v-card-text v-if="musiciansLoading">
          <v-skeleton-loader type="list-item-three-line@3" />
        </v-card-text>
        <v-card-text v-else-if="musicians?.status === 'pending'">
          <v-progress-linear class="mb-4" color="primary" indeterminate rounded />
          <p class="text-medium-emphasis mb-0">{{ t('albums.musicianCreditsPending') }}</p>
        </v-card-text>
        <v-card-text v-else-if="musicians?.status === 'ready'">
          <p class="text-medium-emphasis mb-4">{{ t('albums.musicianCreditsDescription') }}</p>
          <v-list v-if="musicians.data?.musicians.length" lines="three">
            <v-list-item
              v-for="musician in musicians.data.musicians"
              :key="musician.id"
              class="musician-list-item px-0"
              prepend-icon="mdi-account-music-outline"
            >
              <v-list-item-title>
                <RouterLink
                  class="musician-catalog-link"
                  :to="{ name: 'musician-detail', params: { id: musician.id } }"
                >
                  {{ musician.name }}
                </RouterLink>
                <span v-if="musician.disambiguation" class="text-medium-emphasis text-caption">
                  ({{ musician.disambiguation }})
                </span>
              </v-list-item-title>
              <div class="d-flex flex-wrap ga-2 mt-2">
                <v-chip
                  v-for="(credit, creditIndex) in musician.credits"
                  :key="`${musician.id}-${creditIndex}`"
                  size="small"
                  variant="tonal"
                >
                  <strong>{{ musicianCreditLabel(credit) }}</strong>
                  <span class="ml-1 text-medium-emphasis">· {{ musicianTrackScope(credit) }}</span>
                  <span class="ml-1 text-medium-emphasis">· {{ musicianCreditProvider(credit) }}</span>
                </v-chip>
              </div>
            </v-list-item>
          </v-list>
          <v-alert
            v-else
            density="compact"
            icon="mdi-account-question-outline"
            :text="t('albums.noMusicianCredits')"
            type="info"
            variant="tonal"
          />
          <v-btn
            v-if="musicians.data?.sourceUrl"
            class="mt-4 px-0"
            append-icon="mdi-open-in-new"
            variant="text"
            @click="openExternalUrl(musicians.data.sourceUrl)"
          >
            MusicBrainz
          </v-btn>
          <v-btn
            v-if="musicianReleaseCandidates.length > 1"
            class="mt-4 ml-2"
            prepend-icon="mdi-swap-horizontal"
            variant="tonal"
            @click="openMusicianReleaseDialog"
          >
            {{ t('albums.changeMusicianRelease') }}
          </v-btn>
        </v-card-text>
        <v-card-text v-else-if="musicians?.status === 'ambiguous'">
          <v-alert class="mb-4" type="warning" variant="tonal">
            {{ stateText(musicians.status, musicians.errorCode) }}
          </v-alert>
          <v-btn
            v-if="musicianReleaseCandidates.length"
            color="primary"
            prepend-icon="mdi-source-branch"
            variant="tonal"
            @click="openMusicianReleaseDialog"
          >
            {{ t('albums.chooseMusicianRelease') }}
          </v-btn>
        </v-card-text>
        <v-card-text v-else>
          <v-alert
            type="info"
            variant="tonal"
          >
            {{ stateText(musicians?.status, musicians?.errorCode) }}
          </v-alert>
        </v-card-text>
      </v-window-item>
    </v-window>

    <v-dialog v-model="musicianReleaseDialog" max-width="820" scrollable>
      <v-card prepend-icon="mdi-source-branch" :title="t('albums.musicianReleaseTitle')">
        <v-card-text>
          <v-alert v-if="musicianReleaseError" class="mb-4" type="error" variant="tonal">
            {{ musicianReleaseError }}
          </v-alert>
          <p class="text-body-2 text-medium-emphasis mb-4">{{ t('albums.musicianReleaseHint') }}</p>
          <MusicBrainzReleaseChooser
            v-model="selectedMusicianReleaseId"
            :candidates="musicianReleaseCandidates"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="musicianReleaseDialog = false">{{ t('settings.close') }}</v-btn>
          <v-btn
            color="primary"
            :disabled="!selectedMusicianReleaseId"
            :loading="resolvingMusicianRelease"
            variant="flat"
            @click="void resolveMusicianRelease()"
          >
            {{ t('albums.useMusicianRelease') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-card>
</template>

<style scoped>
.musician-catalog-link {
  color: inherit;
  text-decoration: none;
}

.musician-catalog-link:hover {
  color: rgb(var(--v-theme-primary));
  text-decoration: underline;
}

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

.artist-image-attribution {
  color: rgb(var(--v-theme-on-surface-variant));
}

.musician-list-item + .musician-list-item {
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.musician-release-candidate {
  cursor: pointer;
}
</style>

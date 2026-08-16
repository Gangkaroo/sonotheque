<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import AlbumOnlineInformation from '@/components/AlbumOnlineInformation.vue'
import CatalogPagination from '@/components/CatalogPagination.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type { Track } from '@/stores/catalog'
import { useCatalogStore } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'
import { useLibraryRootsStore } from '@/stores/libraryRoots'
import { usePlayerStore, type TrackPlaybackScope } from '@/stores/player'
import { formatDateTime, formatDuration as duration } from '@/utils/formatters'

type ArtistPlaybackAction = 'play' | 'queue'

const LARGE_ARTIST_ACTION_TRACK_COUNT = 500

const { locale, t } = useI18n()
const route = useRoute()
const router = useRouter()
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const libraryRootScope = useLibraryRootScopeStore()
const libraryRoots = useLibraryRootsStore()
const player = usePlayerStore()
const activeTab = ref<'albums' | 'tracks'>(artistDetailTab(route.query.tab))
const albumPage = ref(positivePage(route.query.albumPage))
const trackPage = ref(positivePage(route.query.trackPage))
const artistImageUrl = ref<string | null>(null)
const albumResultsTop = ref<HTMLElement | null>(null)
const trackResultsTop = ref<HTMLElement | null>(null)
const artistActionLoading = ref<ArtistPlaybackAction | null>(null)
const largeActionDialog = ref(false)
const largeActionLoading = ref(false)
const pendingArtistAction = ref<{ action: ArtistPlaybackAction, total: number } | null>(null)
const notice = ref('')
const noticeVisible = ref(false)

const artistId = computed(() => Number(route.params.id))
const artist = computed(() => catalog.artistDetail)
const backToAudioIntelligence = computed(() => route.query.backTo === 'audio-intelligence')
const backRoute = computed(() => backToAudioIntelligence.value
  ? { name: 'settings', query: { tab: 'intelligence' } }
  : { name: 'artists' })
const backLabel = computed(() => backToAudioIntelligence.value
  ? t('artists.backToAudioIntelligence')
  : t('artists.back'))

watch(artistId, (id) => {
  artistImageUrl.value = null
  activeTab.value = artistDetailTab(route.query.tab)
  albumPage.value = positivePage(route.query.albumPage)
  trackPage.value = positivePage(route.query.trackPage)
  void catalog.loadArtist(id)
  if (activeTab.value === 'tracks') {
    void catalog.loadTracks({ artist: id, page: trackPage.value })
  } else {
    void catalog.loadAlbums({ artist: id, page: albumPage.value })
  }
}, { immediate: true })

watch(activeTab, (tab) => {
  if (tab === 'tracks') {
    void catalog.loadTracks({ artist: artistId.value, page: trackPage.value })
  } else {
    void catalog.loadAlbums({ artist: artistId.value, page: albumPage.value })
  }
})

watch(albumPage, (page, previousPage) => {
  if (page !== previousPage) void catalog.loadAlbums({ artist: artistId.value, page })
})

watch(trackPage, (page, previousPage) => {
  if (page !== previousPage && activeTab.value === 'tracks') {
    void catalog.loadTracks({ artist: artistId.value, page })
  }
})

watch([activeTab, albumPage, trackPage], syncDetailStateToRoute)

function changeAlbumPage(value: number) {
  if (value === albumPage.value) return

  albumPage.value = value
  void nextTick(() => {
    albumResultsTop.value?.scrollIntoView({ behavior: 'auto', block: 'start' })
  })
}

function changeTrackPage(value: number) {
  if (value === trackPage.value) return

  trackPage.value = value
  void nextTick(() => {
    trackResultsTop.value?.scrollIntoView({ behavior: 'auto', block: 'start' })
  })
}

function formatDate(value?: string | null) {
  return formatDateTime(value, locale.value, t('artists.neverPlayed'))
}

function toggleTrack(track: Track) {
  if (player.currentTrack?.id === track.id) {
    if (player.isPlaying) {
      player.pause()
    } else {
      player.resume()
    }
    return
  }

  player.playTrack(track, catalog.tracks.items, 'track-list')
}

async function useArtistTracks(action: ArtistPlaybackAction) {
  const currentArtist = artist.value
  if (!currentArtist) return

  artistActionLoading.value = action
  try {
    const result = await catalog.loadArtistPlaybackTracks(
      currentArtist.id,
      LARGE_ARTIST_ACTION_TRACK_COUNT,
    )
    if (result.requiresConfirmation) {
      pendingArtistAction.value = { action, total: result.total }
      largeActionDialog.value = true
      return
    }

    applyArtistPlaybackAction(action, result.tracks, result.total)
  } catch (cause) {
    showNotice(cause instanceof Error ? cause.message : t('artists.actionFailed'))
  } finally {
    artistActionLoading.value = null
  }
}

function applyArtistPlaybackAction(action: ArtistPlaybackAction, tracks: Track[], total: number) {
  const currentArtist = artist.value
  if (!currentArtist) return
  if (!tracks.length) {
    showNotice(t('artists.noPlayableTracks'))
    return
  }

  if (action === 'play') {
    const selectedRoot = libraryRoots.roots.find(root => root.id === libraryRootScope.selectedRootId)
    const scope: TrackPlaybackScope = {
      type: 'tracks',
      libraryRootId: libraryRootScope.selectedRootId,
      libraryRootName: selectedRoot?.name ?? null,
      search: '',
      artistId: currentArtist.id,
      artistName: currentArtist.name,
      genreId: null,
      genreName: '',
      musicianId: null,
      musicianName: '',
      playStatus: null,
      physicalCopy: null,
      sort: 'album',
    }
    player.playTrack(tracks[0]!, tracks, 'track-list', scope)
    return
  }

  player.queueTracks(tracks, 'track-list')
  showNotice(t('artists.tracksQueued', { count: total, artist: currentArtist.name }))
}

async function confirmLargeArtistAction() {
  const currentArtist = artist.value
  const pending = pendingArtistAction.value
  if (!currentArtist || !pending) return

  largeActionLoading.value = true
  try {
    const result = await catalog.loadArtistPlaybackTracks(currentArtist.id)
    resetLargeActionDialog()
    applyArtistPlaybackAction(pending.action, result.tracks, result.total)
  } catch (cause) {
    showNotice(cause instanceof Error ? cause.message : t('artists.actionFailed'))
  } finally {
    largeActionLoading.value = false
  }
}

function closeLargeActionDialog() {
  if (!largeActionLoading.value) resetLargeActionDialog()
}

function resetLargeActionDialog() {
  largeActionDialog.value = false
  pendingArtistAction.value = null
}

function largeActionMessage() {
  const pending = pendingArtistAction.value
  const currentArtist = artist.value
  if (!pending || !currentArtist) return ''

  return t(pending.action === 'play' ? 'artists.largePlayConfirm' : 'artists.largeQueueConfirm', {
    count: pending.total,
    artist: currentArtist.name,
  })
}

function showNotice(message: string) {
  notice.value = message
  noticeVisible.value = true
}

function artistDetailTab(value: unknown): 'albums' | 'tracks' {
  return value === 'tracks' ? 'tracks' : 'albums'
}

function positivePage(value: unknown): number {
  const parsed = typeof value === 'string' ? Number(value) : NaN
  return Number.isInteger(parsed) && parsed > 0 ? parsed : 1
}

function detailStateQuery() {
  return {
    ...(activeTab.value === 'tracks' ? { tab: 'tracks' } : {}),
    ...(albumPage.value > 1 ? { albumPage: String(albumPage.value) } : {}),
    ...(trackPage.value > 1 ? { trackPage: String(trackPage.value) } : {}),
  }
}

function returnContextQuery() {
  return {
    backArtist: String(artistId.value),
    backArtistTab: activeTab.value,
    backArtistAlbumPage: String(albumPage.value),
    backArtistTrackPage: String(trackPage.value),
    ...(backToAudioIntelligence.value ? { backArtistBackTo: 'audio-intelligence' } : {}),
  }
}

function syncDetailStateToRoute() {
  if (route.name !== 'artist-detail') return

  const state = detailStateQuery()
  const nextQuery = { ...route.query }
  delete nextQuery.tab
  delete nextQuery.albumPage
  delete nextQuery.trackPage
  Object.assign(nextQuery, state)
  if (JSON.stringify(route.query) === JSON.stringify(nextQuery)) return

  void router.replace({ name: 'artist-detail', params: { id: artistId.value }, query: nextQuery })
}
</script>

<template>
  <v-btn class="mb-4" variant="text" prepend-icon="mdi-arrow-left" :to="backRoute">
    {{ backLabel }}
  </v-btn>

  <v-alert v-if="catalog.artistDetailError" type="error" variant="tonal">
    {{ catalog.artistDetailError }}
  </v-alert>

  <v-skeleton-loader v-else-if="catalog.artistDetailLoading" type="card, table-heading, list-item-two-line@5" />

  <template v-else-if="artist">
    <v-card border rounded="xl" class="mb-8">
      <v-card-item>
        <template #prepend>
          <v-avatar color="primary" variant="tonal" size="88">
            <v-img
              v-if="artistImageUrl"
              :alt="artist.name"
              cover
              :src="artistImageUrl"
              @error="artistImageUrl = null"
            />
            <v-icon v-else icon="mdi-account-music-outline" size="40" />
          </v-avatar>
        </template>
        <v-card-title>{{ artist.name }}</v-card-title>
        <v-card-subtitle>{{ t('artists.detailDescription') }}</v-card-subtitle>
      </v-card-item>

      <v-card-text>
        <div class="artist-stat-grid">
          <div class="artist-stat-tile">
            <v-icon color="primary" icon="mdi-album" />
            <div>
              <div class="text-caption text-medium-emphasis">{{ t('artists.albums') }}</div>
              <div class="text-h6">{{ artist.albumCount }}</div>
            </div>
          </div>
          <div class="artist-stat-tile">
            <v-icon color="primary" icon="mdi-music-note" />
            <div>
              <div class="text-caption text-medium-emphasis">{{ t('artists.tracks') }}</div>
              <div class="text-h6">{{ artist.trackCount }}</div>
            </div>
          </div>
          <div class="artist-stat-tile">
            <v-icon color="primary" icon="mdi-headphones" />
            <div>
              <div class="text-caption text-medium-emphasis">{{ t('artists.playCount') }}</div>
              <div class="text-h6">{{ artist.playStatistics.playCount }}</div>
            </div>
          </div>
          <div class="artist-stat-tile">
            <v-icon color="primary" icon="mdi-calendar-clock" />
            <div>
              <div class="text-caption text-medium-emphasis">{{ t('artists.lastPlayed') }}</div>
              <div class="text-body-2 font-weight-medium">{{ formatDate(artist.playStatistics.lastPlayedAt) }}</div>
            </div>
          </div>
        </div>
      </v-card-text>
      <v-card-actions class="artist-actions px-4 pb-4 pt-0">
        <v-btn
          color="primary"
          prepend-icon="mdi-play"
          :disabled="artist.trackCount === 0"
          :loading="artistActionLoading === 'play'"
          variant="flat"
          @click="void useArtistTracks('play')"
        >
          {{ t('artists.playAll') }}
        </v-btn>
        <v-btn
          color="primary"
          prepend-icon="mdi-playlist-plus"
          :disabled="artist.trackCount === 0"
          :loading="artistActionLoading === 'queue'"
          variant="tonal"
          @click="void useArtistTracks('queue')"
        >
          {{ t('artists.queueAll') }}
        </v-btn>
      </v-card-actions>
    </v-card>

    <v-card border rounded="xl" class="mb-8">
      <v-tabs v-model="activeTab" color="primary" grow>
        <v-tab prepend-icon="mdi-album" value="albums">{{ t('artists.albums') }} ({{ artist.albumCount }})</v-tab>
        <v-tab prepend-icon="mdi-music-note" value="tracks">{{ t('artists.tracks') }} ({{ artist.trackCount }})</v-tab>
      </v-tabs>
      <v-divider />

      <v-window v-model="activeTab">
        <v-window-item value="albums">
          <v-card-text>
            <div ref="albumResultsTop" class="catalog-results-anchor" />
            <CatalogPagination
              class="mb-4"
              :model-value="albumPage"
              :length="catalog.albums.lastPage"
              @update:model-value="changeAlbumPage"
            />
            <v-alert v-if="catalog.albumsError" type="error" variant="tonal">{{ catalog.albumsError }}</v-alert>
            <v-skeleton-loader v-else-if="catalog.albumsLoading" type="image@3" />
            <v-row v-else-if="catalog.albums.items.length" dense>
              <v-col v-for="album in catalog.albums.items" :key="album.id" cols="12" sm="6" lg="4">
                <v-card
                  :to="{ name: 'album-detail', params: { id: album.id }, query: returnContextQuery() }"
                  variant="tonal"
                  height="100%"
                >
                  <div class="d-flex align-center pa-3 ga-3">
                    <v-avatar rounded="lg" size="64" color="surface-bright">
                      <v-img v-if="album.artworkThumbnailUrl" :src="album.artworkThumbnailUrl" cover />
                      <v-icon v-else icon="mdi-album" />
                    </v-avatar>
                    <div class="min-width-0">
                      <div class="text-body-1 font-weight-bold text-truncate">{{ album.title }}</div>
                      <div class="text-caption text-medium-emphasis">
                        <template v-if="album.originalReleaseYear">{{ album.originalReleaseYear }} · </template>
                        {{ t('albums.trackCount', { count: album.trackCount }) }}
                      </div>
                    </div>
                  </div>
                </v-card>
              </v-col>
            </v-row>
            <EmptyCatalogState v-else :title="t('artists.noAlbums')" :description="t('catalog.scanPrompt')" icon="mdi-album" />
            <CatalogPagination
              class="mt-4"
              :model-value="albumPage"
              :length="catalog.albums.lastPage"
              @update:model-value="changeAlbumPage"
            />
          </v-card-text>
        </v-window-item>

        <v-window-item value="tracks">
          <v-card-text>
            <div ref="trackResultsTop" class="catalog-results-anchor" />
            <CatalogPagination
              class="mb-4"
              :model-value="trackPage"
              :length="catalog.tracks.lastPage"
              @update:model-value="changeTrackPage"
            />
            <v-alert v-if="catalog.tracksError" type="error" variant="tonal">{{ catalog.tracksError }}</v-alert>
            <v-skeleton-loader v-else-if="catalog.tracksLoading" type="list-item-two-line@6" />
            <v-list v-else-if="catalog.tracks.items.length" lines="two">
              <v-list-item v-for="track in catalog.tracks.items" :key="track.id" class="artist-track-item">
                <v-list-item-title class="font-weight-bold">
                  <RouterLink
                    class="catalog-link"
                    :to="{ name: 'track-detail', params: { id: track.id }, query: returnContextQuery() }"
                  >
                    {{ track.title }}
                  </RouterLink>
                </v-list-item-title>
                <v-list-item-subtitle>
                  <RouterLink
                    v-if="track.album"
                    class="catalog-link"
                    :to="{ name: 'album-detail', params: { id: track.album.id }, query: returnContextQuery() }"
                  >
                    {{ track.album.title }}
                  </RouterLink>
                  <span class="ml-2">{{ duration(track.durationMs) }}</span>
                </v-list-item-subtitle>
                <template #append>
                  <div class="d-flex align-center ga-1">
                    <TooltipIconButton
                      :text="player.currentTrack?.id === track.id && player.isPlaying ? t('player.pause') : t('player.play')"
                      :aria-label="player.currentTrack?.id === track.id && player.isPlaying ? t('player.pause') : t('player.play')"
                      :color="player.currentTrack?.id === track.id ? 'primary' : undefined"
                      :icon="player.currentTrack?.id === track.id && player.isPlaying ? 'mdi-pause' : 'mdi-play'"
                      variant="text"
                      @click="toggleTrack(track)"
                    />
                    <TooltipIconButton
                      :text="t('player.queue')"
                      :aria-label="t('player.queue')"
                      icon="mdi-playlist-plus"
                      variant="text"
                      @click="player.queueTrack(track, 'track-list')"
                    />
                    <TooltipIconButton
                      :text="favorites.isTrackFavorite(track.id) ? t('favorites.removeTrack') : t('favorites.addTrack')"
                      :aria-label="favorites.isTrackFavorite(track.id) ? t('favorites.removeTrack') : t('favorites.addTrack')"
                      :color="favorites.isTrackFavorite(track.id) ? 'primary' : undefined"
                      :icon="favorites.isTrackFavorite(track.id) ? 'mdi-heart' : 'mdi-heart-outline'"
                      variant="text"
                      @click="void favorites.toggleTrack(track.id)"
                    />
                  </div>
                </template>
              </v-list-item>
            </v-list>
            <EmptyCatalogState v-else :title="t('artists.noTracks')" :description="t('catalog.scanPrompt')" icon="mdi-music-note" />
            <CatalogPagination
              class="mt-4"
              :model-value="trackPage"
              :length="catalog.tracks.lastPage"
              @update:model-value="changeTrackPage"
            />
          </v-card-text>
        </v-window-item>
      </v-window>
    </v-card>

    <AlbumOnlineInformation
      v-if="artist.representativeTrackId"
      :track-id="artist.representativeTrackId"
      content="artist"
      @artist-image="artistImageUrl = $event"
    />
  </template>

  <v-dialog
    v-model="largeActionDialog"
    max-width="540"
    :persistent="largeActionLoading"
    @after-leave="pendingArtistAction = null"
  >
    <v-card rounded="xl">
      <v-card-title class="d-flex align-center ga-2 pa-5 pb-2">
        <v-icon color="warning" icon="mdi-account-music-outline" />
        {{ t('artists.largeActionTitle') }}
      </v-card-title>
      <v-card-text class="px-5">{{ largeActionMessage() }}</v-card-text>
      <v-card-actions class="px-5 pb-5">
        <v-spacer />
        <v-btn :disabled="largeActionLoading" variant="text" @click="closeLargeActionDialog">
          {{ t('artists.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          :loading="largeActionLoading"
          variant="flat"
          @click="void confirmLargeArtistAction()"
        >
          {{ t('artists.continueAction') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-snackbar v-model="noticeVisible" :timeout="6000">{{ notice }}</v-snackbar>
</template>

<style scoped>
.artist-stat-grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.artist-stat-tile {
  align-items: center;
  background: rgb(var(--v-theme-surface-bright));
  border-radius: 0.75rem;
  display: flex;
  gap: 0.75rem;
  min-height: 72px;
  padding: 0.75rem;
}

.artist-track-item:hover {
  background: rgba(var(--v-theme-primary), 0.08);
}

.artist-actions {
  flex-wrap: wrap;
  gap: 0.75rem;
}

.catalog-link {
  color: inherit;
  text-decoration: none;
}

.catalog-link:hover {
  color: rgb(var(--v-theme-primary));
  text-decoration: underline;
}

.min-width-0 {
  min-width: 0;
}

@media (max-width: 959px) {
  .artist-stat-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 480px) {
  .artist-stat-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import PlayerControls from '@/components/PlayerControls.vue'
import PlayerInformationPanel from '@/components/PlayerInformationPanel.vue'
import PlayerLyricsPanel from '@/components/PlayerLyricsPanel.vue'
import PlayerQueuePanel from '@/components/PlayerQueuePanel.vue'
import SimilarTracksDialog from '@/components/SimilarTracksDialog.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useAudioPlaybackController } from '@/composables/useAudioPlaybackController'
import { useNowPlayingPanelStore } from '@/stores/nowPlayingPanel'
import {
  usePlayerStore,
  type AlbumPlaybackSort,
  type TrackPlaybackSort,
} from '@/stores/player'
import { usePlaylistsStore } from '@/stores/playlists'
import { updateMediaSessionMetadata } from '@/utils/mediaSession'

const { t } = useI18n()
const nowPlayingPanel = useNowPlayingPanelStore()
const player = usePlayerStore()
const playlists = usePlaylistsStore()
const {
  audio,
  currentPlayKey,
  currentTime,
  duration,
  onCanPlay,
  onEnded,
  onError,
  onLoadedMetadata,
  onLoading,
  onPause,
  onPlaying,
  onSeeked,
  onSeeking,
  playbackStateText,
  seekTo,
  showSeekLoading,
  togglePlayback,
  updateDuration,
  updateProgress,
} = useAudioPlaybackController()
const playerCollapsed = ref(false)
const addToPlaylistDialog = ref(false)
const continueMoodDialog = ref(false)
const playlistTracks = ref<typeof player.queue>([])
const createQueuePlaylistDialog = ref(false)
const artworkDialog = ref(false)
const artworkLoading = ref(false)
const queuePlaylistName = ref('')
const queuePlaylistDescription = ref('')
const queuePlaylistFolderId = ref<number | null>(null)
const queuePlaylistSuccess = ref('')
const queuePlaylistSuccessVisible = ref(false)
const nowPlayingDrawerOpen = computed({
  get: () => nowPlayingPanel.isOpen,
  set: (value: boolean) => {
    if (!value) nowPlayingPanel.close()
  },
})
const primaryArtist = computed(() => player.currentTrack?.artists[0] ?? null)
const albumArtworkThumbnailUrl = computed(() => player.currentTrack?.album?.artworkThumbnailUrl ?? null)
const albumArtworkUrl = computed(() => {
  const album = player.currentTrack?.album
  if (!album?.id || !album.artworkThumbnailUrl) return null

  return album.artworkUrl ?? `/api/albums/${album.id}/artwork/original`
})
const artistNames = computed(() => player.currentTrack?.artists.map((artist) => artist.name).join(', ') || t('catalog.unknownArtist'))
const albumReleaseYear = computed(() => player.currentTrack?.album?.originalReleaseYear ?? player.currentTrack?.year ?? null)
const albumTitle = computed(() => {
  const title = player.currentTrack?.album?.title ?? t('catalog.unknownAlbum')

  return albumReleaseYear.value ? `${title} (${albumReleaseYear.value})` : title
})
const trackTitle = computed(() => {
  const title = player.currentTrack?.title ?? ''

  return !player.currentTrack?.album && player.currentTrack?.year ? `${title} (${player.currentTrack.year})` : title
})
const trackRoute = computed(() => {
  const trackId = player.currentTrack?.id

  return trackId ? { name: 'track-detail', params: { id: trackId } } : null
})
const albumRoute = computed(() => {
  const albumId = player.currentTrack?.album?.id

  return albumId ? { name: 'album-detail', params: { id: albumId } } : null
})
const artistRoute = computed(() => {
  return primaryArtist.value
    ? { name: 'artist-detail', params: { id: primaryArtist.value.id } }
    : null
})
const playbackScopeMode = computed(() => {
  const scope = player.playbackScope
  if (scope === null) return ''

  const continuation = player.continuousPlay
    ? t('player.playbackScopeContinuous')
    : t(scope.type === 'albums' ? 'player.playbackScopeCurrentAlbum' : 'player.playbackScopeCurrentTrack')
  const order = scope.type === 'albums'
    ? t(player.randomPlay ? 'player.playbackScopeRandomAlbums' : 'player.playbackScopeSequentialAlbums')
    : t(player.randomPlay ? 'player.playbackScopeRandomTracks' : 'player.playbackScopeSequentialTracks')

  return `${continuation} · ${order}`
})
const playbackScopeLabels = computed(() => {
  const scope = player.playbackScope
  if (scope === null) return []

  const labels = [
    scope.libraryRootId === null
      ? t('player.playbackScopeAllRoots')
      : t('player.playbackScopeRoot', { name: scope.libraryRootName ?? `#${scope.libraryRootId}` }),
  ]

  if (scope.search) labels.push(t('player.playbackScopeSearch', { value: scope.search }))
  if (scope.type === 'tracks' && scope.artistId !== null) {
    labels.push(t('player.playbackScopeArtist', { value: scope.artistName || `#${scope.artistId}` }))
  }
  if (scope.genreId !== null) {
    labels.push(t('player.playbackScopeGenre', { value: scope.genreName || `#${scope.genreId}` }))
  }
  if (scope.type === 'albums' && scope.labelId !== null) {
    labels.push(t('player.playbackScopeLabel', { value: scope.labelName || `#${scope.labelId}` }))
  }
  if (scope.musicianId !== null) {
    labels.push(t('player.playbackScopeMusician', { value: scope.musicianName || `#${scope.musicianId}` }))
  }
  if (scope.type === 'albums') {
    if (scope.initial) labels.push(t('player.playbackScopeInitial', { value: scope.initial }))
    if (scope.year !== null) labels.push(t('player.playbackScopeYear', { value: scope.year }))
  } else if (scope.playStatus === 'never') {
    labels.push(t('player.playbackScopeNeverPlayed'))
  }
  if (scope.physicalCopy === 'owned') labels.push(t('player.playbackScopeOwned'))
  if (scope.physicalCopy === 'not_owned') labels.push(t('player.playbackScopeNotOwned'))
  if (!player.randomPlay) {
    const sort = scope.type === 'albums' ? albumSortLabel(scope.sort) : trackSortLabel(scope.sort)
    labels.push(t('player.playbackScopeSort', { value: sort }))
  }

  return labels
})
const playbackScopeIcon = computed(() => player.playbackScope?.type === 'tracks'
  ? 'mdi-music-note-multiple'
  : 'mdi-album-multiple')
const playlistFolderOptions = computed(() => [
  { title: t('playlists.noFolder'), value: null },
  ...playlists.folders.map((folder) => ({ title: folder.name, value: folder.id })),
])
const canCreateQueuePlaylist = computed(() => queuePlaylistName.value.trim().length > 0 && player.queue.length > 0 && !playlists.saving)

function albumSortLabel(sort: AlbumPlaybackSort) {
  const labels = {
    artist: t('albums.sortArtist'),
    title: t('albums.sortTitle'),
    year_asc: t('albums.sortYearOldest'),
    year_desc: t('albums.sortYearNewest'),
    plays: t('albums.sortMostPlayed'),
    last_played: t('albums.sortLastPlayed'),
    added: t('albums.sortRecentlyAdded'),
  }

  return labels[sort]
}

function trackSortLabel(sort: TrackPlaybackSort) {
  const labels = {
    album: t('tracks.sortAlbum'),
    title: t('tracks.sortTitle'),
    year_asc: t('tracks.sortYearOldest'),
    year_desc: t('tracks.sortYearNewest'),
    plays: t('tracks.sortMostPlayed'),
    last_played: t('tracks.sortLastPlayed'),
    added: t('tracks.sortRecentlyAdded'),
  }

  return labels[sort]
}

watch(
  [
    () => player.currentTrack?.id,
    trackTitle,
    artistNames,
    albumTitle,
    albumArtworkThumbnailUrl,
  ],
  ([trackId, title, artist, album, artworkUrl]) => {
    updateMediaSessionMetadata(trackId
      ? { album, artist, artworkUrl, title }
      : null)
  },
  { immediate: true },
)

watch(createQueuePlaylistDialog, (open) => {
  if (!open) return

  queuePlaylistName.value = ''
  queuePlaylistDescription.value = ''
  queuePlaylistFolderId.value = null
  void playlists.loadAll()
})

function openArtwork() {
  if (!albumArtworkUrl.value) return

  artworkLoading.value = true
  artworkDialog.value = true
}

function finishArtworkLoading() {
  artworkLoading.value = false
}

function toggleQueueItem(index: number) {
  if (index === player.currentIndex) {
    if (player.isPlaying) {
      player.pause()
    } else {
      player.resume()
    }
    return
  }

  player.playQueueIndex(index)
}

function openAddToPlaylist(tracks: typeof player.queue) {
  playlistTracks.value = [...tracks]
  addToPlaylistDialog.value = true
}

async function createPlaylistFromQueue() {
  if (!canCreateQueuePlaylist.value) return

  const playlist = await playlists.createPlaylist({
    name: queuePlaylistName.value.trim(),
    description: queuePlaylistDescription.value.trim() || null,
    folderId: queuePlaylistFolderId.value,
  })
  await playlists.addTracks(playlist.id, player.queue.map((track) => track.id))
  queuePlaylistSuccess.value = t('playlists.createdFromQueue', { name: playlist.name })
  queuePlaylistSuccessVisible.value = true
  createQueuePlaylistDialog.value = false
}

</script>

<template>
  <v-footer v-if="player.currentTrack" app border class="player-footer" :class="{ 'is-collapsed': playerCollapsed }">
    <audio
      :key="currentPlayKey ?? undefined"
      ref="audio"
      :data-play-key="currentPlayKey ?? ''"
      :src="player.currentTrack.streamUrl"
      preload="metadata"
      :volume="player.volume"
      @canplay="onCanPlay"
      @durationchange="updateDuration"
      @ended="onEnded"
      @error="onError"
      @loadedmetadata="onLoadedMetadata"
      @loadstart="onLoading"
      @pause="onPause"
      @playing="onPlaying"
      @seeked="onSeeked"
      @seeking="onSeeking"
      @stalled="onLoading"
      @timeupdate="updateProgress"
      @waiting="onLoading"
    />

    <PlayerControls
      v-model:collapsed="playerCollapsed"
      :album-artwork-thumbnail-url="albumArtworkThumbnailUrl"
      :album-artwork-url="albumArtworkUrl"
      :album-route="albumRoute"
      :album-title="albumTitle"
      :artist-names="artistNames"
      :artist-route="artistRoute"
      :audio-element="audio"
      :current-time="currentTime"
      :duration="duration"
      :playback-state-text="playbackStateText"
      :show-seek-loading="showSeekLoading"
      :track-route="trackRoute"
      :track-title="trackTitle"
      @open-artwork="openArtwork"
      @seek="seekTo"
      @toggle-playback="togglePlayback"
    />
  </v-footer>

  <v-dialog
    v-model="artworkDialog"
    class="player-artwork-dialog"
    max-width="none"
    @after-leave="artworkLoading = false"
  >
    <div class="player-artwork-overlay" @click="artworkDialog = false">
      <v-progress-circular
        v-if="artworkLoading"
        class="player-artwork-loading"
        color="primary"
        indeterminate
        size="52"
        width="4"
      />
      <img
        v-if="albumArtworkUrl"
        :alt="albumTitle"
        class="player-artwork-full"
        :class="{ 'is-loading': artworkLoading }"
        :src="albumArtworkUrl"
        @error="finishArtworkLoading"
        @load="finishArtworkLoading"
      >
    </div>
  </v-dialog>

  <v-navigation-drawer
    v-model="nowPlayingDrawerOpen"
    class="now-playing-drawer"
    location="right"
    temporary
    width="520"
  >
    <div class="now-playing-drawer-header pa-4">
      <button
        v-if="albumArtworkUrl && albumArtworkThumbnailUrl"
        :aria-label="t('player.expandArtwork')"
        class="now-playing-drawer-artwork"
        :title="t('player.expandArtwork')"
        type="button"
        @click="openArtwork"
      >
        <v-img :alt="albumTitle" cover :src="albumArtworkThumbnailUrl" />
      </button>
      <div v-else class="now-playing-drawer-artwork now-playing-drawer-artwork-placeholder">
        <v-icon icon="mdi-album" size="28" />
      </div>

      <div class="now-playing-drawer-meta">
        <RouterLink
          v-if="trackRoute"
          class="now-playing-drawer-link text-subtitle-1 font-weight-bold"
          :to="trackRoute"
          @click="nowPlayingPanel.close"
        >
          {{ trackTitle }}
        </RouterLink>
        <div v-else class="text-subtitle-1 font-weight-bold text-truncate">{{ trackTitle }}</div>
        <RouterLink
          v-if="artistRoute"
          class="now-playing-drawer-link text-body-2 text-medium-emphasis"
          :to="artistRoute"
          @click="nowPlayingPanel.close"
        >
          {{ artistNames }}
        </RouterLink>
        <div v-else class="text-body-2 text-medium-emphasis text-truncate">{{ artistNames }}</div>
        <RouterLink
          v-if="albumRoute"
          class="now-playing-drawer-link text-caption text-medium-emphasis"
          :to="albumRoute"
          @click="nowPlayingPanel.close"
        >
          {{ albumTitle }}
        </RouterLink>
        <div v-else class="text-caption text-medium-emphasis text-truncate">{{ albumTitle }}</div>
      </div>

      <TooltipIconButton
        :text="t('player.closeNowPlaying')"
        :aria-label="t('player.closeNowPlaying')"
        icon="mdi-close"
        variant="text"
        @click="nowPlayingPanel.close"
      />
    </div>

    <v-tabs v-model="nowPlayingPanel.activeTab" color="primary" grow>
      <v-tab prepend-icon="mdi-playlist-music-outline" value="queue">{{ t('player.queue') }}</v-tab>
      <v-tab prepend-icon="mdi-information-outline" value="info">{{ t('player.info') }}</v-tab>
      <v-tab prepend-icon="mdi-text-box-outline" value="lyrics">{{ t('player.lyrics') }}</v-tab>
    </v-tabs>
    <v-divider />

    <v-window v-model="nowPlayingPanel.activeTab">
      <v-window-item value="queue">
        <PlayerQueuePanel
          :current-index="player.currentIndex"
          :is-playing="player.isPlaying"
          :playback-scope-icon="playbackScopeIcon"
          :playback-scope-labels="playbackScopeLabels"
          :playback-scope-mode="playbackScopeMode"
          :queue="player.queue"
          @add-to-playlist="openAddToPlaylist"
          @clear="player.clearQueue"
          @continue-mood="continueMoodDialog = true"
          @create-playlist="createQueuePlaylistDialog = true"
          @move="player.moveQueuedTrack"
          @remove="player.removeQueuedTrack"
          @toggle="toggleQueueItem"
        />
      </v-window-item>
      <v-window-item value="info">
        <PlayerInformationPanel
          :active="nowPlayingPanel.isOpen && nowPlayingPanel.activeTab === 'info'"
          :album-title="albumTitle"
          :artist-name="artistNames"
          :track-id="player.currentTrack?.id ?? null"
        />
      </v-window-item>

      <v-window-item eager value="lyrics">
        <PlayerLyricsPanel
          :active="nowPlayingPanel.isOpen && nowPlayingPanel.activeTab === 'lyrics'"
          :current-time="currentTime"
          :is-playing="player.isPlaying"
          :track-id="player.currentTrack?.id ?? null"
          @seek="seekTo"
        />
      </v-window-item>
    </v-window>
  </v-navigation-drawer>

  <SimilarTracksDialog
    v-model="continueMoodDialog"
    mode="mood"
    :track-id="player.currentTrack?.id ?? null"
  />

  <AddToPlaylistDialog v-model="addToPlaylistDialog" :tracks="playlistTracks" />

  <v-dialog v-model="createQueuePlaylistDialog" max-width="560">
    <v-card rounded="xl" prepend-icon="mdi-playlist-plus" :title="t('playlists.createFromQueueTitle')">
      <v-card-text>
        <v-alert v-if="playlists.error" class="mb-4" type="error" variant="tonal">
          {{ playlists.error }}
        </v-alert>
        <v-row dense>
          <v-col cols="12">
            <v-text-field
              v-model="queuePlaylistName"
              autofocus
              :disabled="playlists.saving"
              :label="t('playlists.playlistName')"
              variant="outlined"
              @keydown.enter.prevent="createPlaylistFromQueue"
            />
          </v-col>
          <v-col cols="12">
            <v-select
              v-model="queuePlaylistFolderId"
              :disabled="playlists.saving"
              :items="playlistFolderOptions"
              :label="t('playlists.folder')"
              variant="outlined"
            />
          </v-col>
          <v-col cols="12">
            <v-textarea
              v-model="queuePlaylistDescription"
              :disabled="playlists.saving"
              :label="t('playlists.descriptionField')"
              rows="3"
              variant="outlined"
            />
          </v-col>
        </v-row>
        <div class="text-caption text-medium-emphasis">
          {{ t('playlists.createFromQueueDescription', { count: player.queue.length }) }}
        </div>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" @click="createQueuePlaylistDialog = false">
          {{ t('settings.cancel') }}
        </v-btn>
        <v-btn color="primary" :disabled="!canCreateQueuePlaylist" :loading="playlists.saving" variant="flat" @click="createPlaylistFromQueue">
          {{ t('playlists.createFromQueue') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-snackbar v-model="queuePlaylistSuccessVisible" color="primary" timeout="2500">
    {{ queuePlaylistSuccess }}
  </v-snackbar>
</template>

<style scoped>
.player-footer {
  display: block;
  padding: 10px 18px;
}

.player-footer.is-collapsed {
  padding-block: 6px;
  position: relative;
}

.player-artwork-overlay {
  align-items: center;
  cursor: zoom-out;
  display: flex;
  justify-content: center;
  min-height: 100vh;
  padding: 24px;
  position: relative;
}

.player-artwork-loading {
  left: 50%;
  position: absolute;
  top: 50%;
  transform: translate(-50%, -50%);
}

.player-artwork-full {
  display: block;
  height: auto;
  max-height: 92vh;
  max-width: 92vw;
  opacity: 1;
  transition: opacity 160ms ease;
  width: auto;
}

.player-artwork-full.is-loading {
  opacity: 0;
}

.now-playing-drawer-header {
  align-items: center;
  display: grid;
  gap: 12px;
  grid-template-columns: 56px minmax(0, 1fr) auto;
}

.now-playing-drawer-artwork {
  appearance: none;
  background: transparent;
  border: 0;
  border-radius: 10px;
  cursor: zoom-in;
  height: 56px;
  overflow: hidden;
  padding: 0;
  width: 56px;
}

.now-playing-drawer-artwork-placeholder {
  align-items: center;
  background: rgba(var(--v-theme-primary), 0.12);
  color: rgb(var(--v-theme-primary));
  display: flex;
  justify-content: center;
}

.now-playing-drawer-meta {
  display: grid;
  min-width: 0;
}

.now-playing-drawer-link {
  color: inherit;
  overflow: hidden;
  text-decoration: none;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.now-playing-drawer-link:hover {
  text-decoration: underline;
}

@media (max-width: 560px) {
  :deep(.now-playing-drawer) {
    max-width: 100vw;
  }
}
</style>

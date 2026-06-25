<script setup lang="ts">
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import type { Track } from '@/stores/catalog'
import { usePlayerStore } from '@/stores/player'
import { usePlaylistsStore } from '@/stores/playlists'

const { t } = useI18n()
const route = useRoute()
const playlists = usePlaylistsStore()
const player = usePlayerStore()

const playlistId = computed(() => Number(route.params.id))
const playlist = computed(() => playlists.current)
const tracks = computed(() => playlist.value?.items.map((item) => item.track) ?? [])

function duration(milliseconds?: number) {
  if (!milliseconds) return '-'
  const seconds = Math.round(milliseconds / 1000)
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

function playPlaylist() {
  const [firstTrack] = tracks.value
  if (!firstTrack) return

  player.playTrack(firstTrack, tracks.value, 'track-list')
}

function queuePlaylist() {
  player.queueTracks(tracks.value, 'track-list')
}

function toggleTrack(track: Track) {
  if (player.currentTrack?.id === track.id && player.isPlaying) {
    player.pause()
    return
  }

  player.playTrack(track, tracks.value, 'track-list')
}

watch(playlistId, (id) => {
  if (Number.isInteger(id) && id > 0) void playlists.loadPlaylist(id)
}, { immediate: true })
</script>

<template>
  <v-btn class="mb-4" variant="text" prepend-icon="mdi-arrow-left" :to="{ name: 'playlists' }">
    {{ t('playlists.back') }}
  </v-btn>

  <v-alert v-if="playlists.error" type="error" variant="tonal">
    {{ playlists.error }}
  </v-alert>

  <v-skeleton-loader v-else-if="playlists.loading" type="card, list-item-three-line@8" />

  <template v-else-if="playlist">
    <v-card class="mb-6" border rounded="xl">
      <v-card-item>
        <template #prepend>
          <v-avatar color="primary" variant="tonal" size="44">
            <v-icon icon="mdi-playlist-music-outline" />
          </v-avatar>
        </template>
        <v-card-title>{{ playlist.name }}</v-card-title>
        <v-card-subtitle>
          {{ playlist.folder?.name ?? t('playlists.noFolder') }} · {{ t('playlists.trackCount', { count: playlist.trackCount }) }}
        </v-card-subtitle>
      </v-card-item>
      <v-card-text v-if="playlist.description" class="text-medium-emphasis">
        {{ playlist.description }}
      </v-card-text>
      <v-card-actions>
        <v-btn color="primary" variant="flat" prepend-icon="mdi-play" :disabled="!tracks.length" @click="playPlaylist">
          {{ t('playlists.playPlaylist') }}
        </v-btn>
        <v-btn color="primary" variant="tonal" prepend-icon="mdi-playlist-plus" :disabled="!tracks.length" @click="queuePlaylist">
          {{ t('playlists.queuePlaylist') }}
        </v-btn>
      </v-card-actions>
    </v-card>

    <v-list v-if="playlist.items.length" border rounded="xl" lines="three">
      <v-list-item
        v-for="item in playlist.items"
        :key="item.id"
        :class="{ 'current-track': player.currentTrack?.id === item.track.id }"
      >
        <v-list-item-title class="font-weight-bold" :class="{ 'text-primary': player.currentTrack?.id === item.track.id }">
          <RouterLink class="playlist-track-link" :to="{ name: 'track-detail', params: { id: item.track.id } }">
            {{ item.track.title }}
          </RouterLink>
        </v-list-item-title>
        <v-list-item-subtitle>
          <template v-if="item.track.artists.length">
            <template v-for="(artist, index) in item.track.artists" :key="artist.id">
              <span v-if="index > 0">, </span>
              <RouterLink class="playlist-track-link" :to="{ name: 'albums', query: { search: artist.name } }">
                {{ artist.name }}
              </RouterLink>
            </template>
          </template>
          <span v-else>{{ t('catalog.unknownArtist') }}</span>
        </v-list-item-subtitle>
        <v-list-item-subtitle>
          <RouterLink
            v-if="item.track.album"
            class="playlist-track-link"
            :to="{ name: 'album-detail', params: { id: item.track.album.id } }"
          >
            {{ item.track.album.title }}
          </RouterLink>
          <span v-else>{{ t('catalog.unknownAlbum') }}</span>
        </v-list-item-subtitle>
        <template #append>
          <div class="d-flex align-center ga-2">
            <span class="text-caption text-medium-emphasis">{{ duration(item.track.durationMs) }}</span>
            <v-btn
              :aria-label="player.currentTrack?.id === item.track.id && player.isPlaying ? t('player.pause') : t('player.play')"
              :color="player.currentTrack?.id === item.track.id ? 'primary' : undefined"
              :icon="player.currentTrack?.id === item.track.id && player.isPlaying ? 'mdi-pause' : 'mdi-play'"
              variant="text"
              @click="toggleTrack(item.track)"
            />
            <v-btn
              :aria-label="t('tracks.queueTrack')"
              icon="mdi-playlist-plus"
              variant="text"
              @click="player.queueTrack(item.track, 'track-list')"
            />
            <v-btn
              :aria-label="t('playlists.removeTrack')"
              :disabled="playlists.saving"
              icon="mdi-delete-outline"
              variant="text"
              @click="void playlists.removeItem(playlist.id, item.id)"
            />
          </div>
        </template>
      </v-list-item>
    </v-list>

    <EmptyCatalogState
      v-else
      :title="t('playlists.emptyPlaylistTitle')"
      :description="t('playlists.emptyPlaylistDescription')"
      icon="mdi-playlist-music-outline"
    />
  </template>

  <EmptyCatalogState v-else :title="t('playlists.notFoundTitle')" :description="t('playlists.notFoundDescription')" icon="mdi-playlist-remove" />
</template>

<style scoped>
.current-track {
  background: rgba(var(--v-theme-primary), 0.08);
}

.playlist-track-link {
  color: inherit;
  text-decoration: none;
}

.playlist-track-link:hover {
  text-decoration: underline;
}
</style>

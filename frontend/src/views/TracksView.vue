<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import type { Track } from '@/stores/catalog'
import { useCatalogStore } from '@/stores/catalog'
import { usePlayerStore } from '@/stores/player'

const { t } = useI18n()
const catalog = useCatalogStore()
const player = usePlayerStore()
const search = ref('')
const page = ref(1)
let searchTimer: ReturnType<typeof setTimeout> | null = null

function duration(milliseconds?: number) {
  if (!milliseconds) return '—'
  const seconds = Math.round(milliseconds / 1000)
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

function load() {
  void catalog.loadTracks({ page: page.value, search: search.value })
}

function toggleTrack(track: Track) {
  if (player.currentTrack?.id === track.id && player.isPlaying) {
    player.pause()
    return
  }

  player.playTrack(track, catalog.tracks.items)
}

watch(page, load, { immediate: true })
watch(search, () => {
  if (searchTimer) clearTimeout(searchTimer)
  if (page.value !== 1) {
    page.value = 1
    return
  }
  searchTimer = setTimeout(load, 300)
})
onUnmounted(() => {
  if (searchTimer) clearTimeout(searchTimer)
})
</script>

<template>
  <PageHeader :title="t('tracks.title')" :description="t('tracks.description')" icon="mdi-music-note-outline" />
  <v-text-field v-model="search" class="mb-6" clearable hide-details prepend-inner-icon="mdi-magnify" :label="t('tracks.search')" />
  <v-alert v-if="catalog.tracksError" type="error" variant="tonal">{{ catalog.tracksError }}</v-alert>
  <v-skeleton-loader v-else-if="catalog.tracksLoading" type="list-item-three-line@8" />
  <v-list v-else-if="catalog.tracks.items.length" border rounded="xl" lines="three">
    <v-list-item v-for="track in catalog.tracks.items" :key="track.id" prepend-icon="mdi-music-note">
      <v-list-item-title class="font-weight-bold">{{ track.title }}</v-list-item-title>
      <v-list-item-subtitle>
        {{ track.artists.map((artist) => artist.name).join(', ') || t('catalog.unknownArtist') }}
      </v-list-item-subtitle>
      <v-list-item-subtitle>{{ track.album?.title ?? t('catalog.unknownAlbum') }}</v-list-item-subtitle>
      <template #append>
        <div class="d-flex align-center ga-2">
          <span class="text-caption text-medium-emphasis">{{ duration(track.durationMs) }}</span>
          <v-btn
            :aria-label="player.currentTrack?.id === track.id && player.isPlaying ? t('player.pause') : t('player.play')"
            :color="player.currentTrack?.id === track.id ? 'primary' : undefined"
            :icon="player.currentTrack?.id === track.id && player.isPlaying ? 'mdi-pause' : 'mdi-play'"
            variant="text"
            @click="toggleTrack(track)"
          />
        </div>
      </template>
    </v-list-item>
  </v-list>
  <EmptyCatalogState v-else :title="t('tracks.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-music-note-outline" />
  <v-pagination v-if="catalog.tracks.lastPage > 1" v-model="page" class="mt-6" :length="catalog.tracks.lastPage" />
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import type { Album } from '@/stores/catalog'
import { useCatalogStore } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { usePlayerStore } from '@/stores/player'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const player = usePlayerStore()
const initial = ref<string | null>(null)
const search = ref(querySearch(route.query.search))
const genre = ref(queryNumber(route.query.genre))
const genreName = ref(querySearch(route.query.genreName))
const year = ref<number | null>(null)
const page = ref(1)
let searchTimer: ReturnType<typeof setTimeout> | null = null
const releaseYears = computed(() => {
  const currentYear = new Date().getFullYear()

  return Array.from({ length: currentYear - 1950 + 1 }, (_, index) => currentYear - index)
})

function load() {
  void catalog.loadAlbums({ page: page.value, search: search.value, initial: initial.value, year: year.value, genre: genre.value })
}

function selectInitial(value: string | null) {
  page.value = 1
  initial.value = value
}

function querySearch(value: unknown) {
  return typeof value === 'string' ? value : ''
}

function queryNumber(value: unknown) {
  const parsed = typeof value === 'string' ? Number(value) : NaN

  return Number.isInteger(parsed) && parsed > 0 ? parsed : null
}

function clearGenreFilter() {
  const query = { ...route.query }
  delete query.genre
  delete query.genreName
  genre.value = null
  genreName.value = ''
  page.value = 1
  void router.replace({ name: 'albums', query })
}

function albumDetails(album: Album) {
  if (album.originalReleaseYear === undefined || album.originalReleaseYear === null) {
    return t('albums.trackCount', { count: album.trackCount })
  }

  return t('albums.details', { year: album.originalReleaseYear, count: album.trackCount })
}

watch([page, initial, year, genre], load, { immediate: true })
watch(() => route.query.search, (value) => {
  search.value = querySearch(value)
  page.value = 1
})
watch(() => [route.query.genre, route.query.genreName], ([genreValue, genreNameValue]) => {
  genre.value = queryNumber(genreValue)
  genreName.value = querySearch(genreNameValue)
  page.value = 1
})
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
  <PageHeader :title="t('albums.title')" :description="t('albums.description')" icon="mdi-album" />
  <div class="d-flex flex-wrap ga-3 mb-4">
    <v-btn color="primary" prepend-icon="mdi-album" variant="flat" @click="void player.playRandomAlbum()">
      {{ t('player.playRandomAlbum') }}
    </v-btn>
    <v-btn color="primary" prepend-icon="mdi-shuffle-variant" variant="tonal" @click="void player.playRandomTrack()">
      {{ t('player.playRandomTrack') }}
    </v-btn>
  </div>
  <div class="d-flex flex-column flex-sm-row ga-3 mb-4">
    <v-text-field v-model="search" clearable hide-details prepend-inner-icon="mdi-magnify" :label="t('albums.search')" />
    <v-autocomplete
      v-model="year"
      class="album-year-filter"
      clearable
      hide-details
      :items="releaseYears"
      prepend-inner-icon="mdi-calendar"
      :label="t('albums.releaseYear')"
    />
  </div>
  <div class="d-flex flex-wrap ga-1 mb-6">
    <v-btn size="small" :variant="initial === null ? 'flat' : 'tonal'" @click="selectInitial(null)">{{ t('albums.all') }}</v-btn>
    <v-btn
      v-for="letter in ['#', ...'ABCDEFGHIJKLMNOPQRSTUVWXYZ']"
      :key="letter"
      size="small"
      :variant="initial === letter ? 'flat' : 'tonal'"
      @click="selectInitial(letter)"
    >
      {{ letter }}
    </v-btn>
  </div>
  <div v-if="genre" class="mb-6">
    <v-chip closable color="primary" variant="tonal" @click:close="clearGenreFilter">
      {{ t('genres.filterLabel', { name: genreName || t('genres.filterFallback', { id: genre }) }) }}
    </v-chip>
  </div>
  <v-alert v-if="catalog.albumsError" type="error" variant="tonal">{{ catalog.albumsError }}</v-alert>
  <v-skeleton-loader v-else-if="catalog.albumsLoading" type="card@3" />
  <v-row v-else-if="catalog.albums.items.length">
    <v-col v-for="album in catalog.albums.items" :key="album.id" cols="12" sm="6" lg="4" xl="3">
      <v-card border rounded="xl" height="100%" hover :to="{ name: 'album-detail', params: { id: album.id } }">
        <div class="album-card-media">
          <v-img v-if="album.artworkThumbnailUrl" :src="album.artworkThumbnailUrl" aspect-ratio="1" cover />
          <div v-else class="d-flex align-center justify-center bg-surface-bright" style="aspect-ratio: 1">
            <v-icon icon="mdi-album" size="72" color="medium-emphasis" />
          </div>
          <v-btn
            class="favorite-album-button"
            :aria-label="favorites.isAlbumFavorite(album.id) ? t('favorites.removeAlbum') : t('favorites.addAlbum')"
            :color="favorites.isAlbumFavorite(album.id) ? 'primary' : undefined"
            :icon="favorites.isAlbumFavorite(album.id) ? 'mdi-heart' : 'mdi-heart-outline'"
            variant="flat"
            @click.prevent.stop="void favorites.toggleAlbum(album.id)"
          />
        </div>
        <v-card-item>
          <v-card-title>{{ album.title }}</v-card-title>
          <v-card-subtitle>{{ album.primaryArtist?.name ?? t('catalog.unknownArtist') }}</v-card-subtitle>
        </v-card-item>
        <v-card-text class="pt-0 text-medium-emphasis">
          {{ albumDetails(album) }}
        </v-card-text>
      </v-card>
    </v-col>
  </v-row>
  <EmptyCatalogState v-else :title="t('albums.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-album" />
  <v-pagination v-if="catalog.albums.lastPage > 1" v-model="page" class="mt-6" :length="catalog.albums.lastPage" />
</template>

<style scoped>
.album-year-filter {
  flex: 0 0 12rem;
}

.album-card-media {
  position: relative;
}

.favorite-album-button {
  position: absolute;
  right: 12px;
  top: 12px;
}
</style>

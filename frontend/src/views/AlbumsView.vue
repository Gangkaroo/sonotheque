<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import { useCatalogStore } from '@/stores/catalog'

const { t } = useI18n()
const catalog = useCatalogStore()
const search = ref('')
const page = ref(1)
let searchTimer: ReturnType<typeof setTimeout> | null = null

function load() {
  void catalog.loadAlbums({ page: page.value, search: search.value })
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
  <PageHeader :title="t('albums.title')" :description="t('albums.description')" icon="mdi-album" />
  <v-text-field v-model="search" class="mb-6" clearable hide-details prepend-inner-icon="mdi-magnify" :label="t('albums.search')" />
  <v-alert v-if="catalog.albumsError" type="error" variant="tonal">{{ catalog.albumsError }}</v-alert>
  <v-skeleton-loader v-else-if="catalog.albumsLoading" type="card@3" />
  <v-row v-else-if="catalog.albums.items.length">
    <v-col v-for="album in catalog.albums.items" :key="album.id" cols="12" sm="6" lg="4" xl="3">
      <v-card border rounded="xl" height="100%">
        <v-img v-if="album.artworkThumbnailUrl" :src="album.artworkThumbnailUrl" aspect-ratio="1" cover />
        <div v-else class="d-flex align-center justify-center bg-surface-bright" style="aspect-ratio: 1">
          <v-icon icon="mdi-album" size="72" color="medium-emphasis" />
        </div>
        <v-card-item>
          <v-card-title>{{ album.title }}</v-card-title>
          <v-card-subtitle>{{ album.primaryArtist?.name ?? t('catalog.unknownArtist') }}</v-card-subtitle>
        </v-card-item>
        <v-card-text class="pt-0 text-medium-emphasis">
          {{ t('albums.details', { year: album.originalReleaseYear ?? t('catalog.unknownYear'), count: album.trackCount }) }}
        </v-card-text>
      </v-card>
    </v-col>
  </v-row>
  <EmptyCatalogState v-else :title="t('albums.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-album" />
  <v-pagination v-if="catalog.albums.lastPage > 1" v-model="page" class="mt-6" :length="catalog.albums.lastPage" />
</template>

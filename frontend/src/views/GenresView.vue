<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useCatalogStore } from '@/stores/catalog'

const { t } = useI18n()
const catalog = useCatalogStore()
const search = ref('')
const page = ref(1)
let searchTimer: ReturnType<typeof setTimeout> | null = null

function load() {
  void catalog.loadGenres({ page: page.value, search: search.value })
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
  <PageHeader
    :title="t('genres.title')"
    :count="catalog.genresLoading || catalog.genresError ? undefined : catalog.genres.total"
    :description="t('genres.description')"
    icon="mdi-tag-multiple-outline"
  />
  <v-text-field v-model="search" class="mb-6" clearable hide-details prepend-inner-icon="mdi-magnify" :label="t('genres.search')" />
  <v-alert v-if="catalog.genresError" type="error" variant="tonal">{{ catalog.genresError }}</v-alert>
  <v-skeleton-loader v-else-if="catalog.genresLoading" type="list-item-two-line@5" />
  <v-list v-else-if="catalog.genres.items.length" border rounded="xl" lines="two">
    <v-list-item v-for="genre in catalog.genres.items" :key="genre.id" prepend-icon="mdi-tag-outline">
      <v-list-item-title class="font-weight-bold">{{ genre.name }}</v-list-item-title>
      <v-list-item-subtitle>{{ t('genres.trackCount', { count: genre.trackCount }) }}</v-list-item-subtitle>
      <template #append>
        <div class="d-flex align-center ga-1">
          <TooltipIconButton
            :text="t('genres.viewAlbums', { name: genre.name })"
            :aria-label="t('genres.viewAlbums', { name: genre.name })"
            icon="mdi-album"
            size="small"
            :to="{ name: 'albums', query: { genre: genre.id, genreName: genre.name } }"
            variant="text"
          />
          <TooltipIconButton
            :text="t('genres.viewTracks', { name: genre.name })"
            :aria-label="t('genres.viewTracks', { name: genre.name })"
            icon="mdi-music-note"
            size="small"
            :to="{ name: 'tracks', query: { genre: genre.id, genreName: genre.name } }"
            variant="text"
          />
        </div>
      </template>
    </v-list-item>
  </v-list>
  <EmptyCatalogState v-else :title="t('genres.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-tag-multiple-outline" />
  <v-pagination v-if="catalog.genres.lastPage > 1" v-model="page" class="mt-6" :length="catalog.genres.lastPage" />
</template>

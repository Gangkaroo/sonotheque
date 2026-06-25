<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useCatalogStore } from '@/stores/catalog'

const { t } = useI18n()
const catalog = useCatalogStore()
const initial = ref<string | null>(null)
const search = ref('')
const page = ref(1)
let searchTimer: ReturnType<typeof setTimeout> | null = null

function load() {
  void catalog.loadArtists({ page: page.value, search: search.value, initial: initial.value })
}

function selectInitial(value: string | null) {
  page.value = 1
  initial.value = value
}

watch([page, initial], load, { immediate: true })
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
  <PageHeader :title="t('artists.title')" :description="t('artists.description')" icon="mdi-account-music-outline" />
  <v-text-field v-model="search" class="mb-4" clearable hide-details prepend-inner-icon="mdi-magnify" :label="t('artists.search')" />
  <div class="d-flex flex-wrap ga-1 mb-6">
    <v-btn size="small" :variant="initial === null ? 'flat' : 'tonal'" @click="selectInitial(null)">{{ t('artists.all') }}</v-btn>
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
  <v-alert v-if="catalog.artistsError" type="error" variant="tonal">{{ catalog.artistsError }}</v-alert>
  <v-skeleton-loader v-else-if="catalog.artistsLoading" type="list-item-two-line@6" />
  <v-list v-else-if="catalog.artists.items.length" border rounded="xl" lines="two">
    <v-list-item v-for="artist in catalog.artists.items" :key="artist.id" prepend-icon="mdi-account-music-outline">
      <v-list-item-title class="font-weight-bold">{{ artist.name }}</v-list-item-title>
      <v-list-item-subtitle>{{ t('artists.albumCount', { count: artist.albumCount }) }}</v-list-item-subtitle>
      <template #append>
        <div class="d-flex align-center ga-1">
          <TooltipIconButton
            :text="t('artists.viewAlbums', { name: artist.name })"
            :aria-label="t('artists.viewAlbums', { name: artist.name })"
            :disabled="artist.albumCount === 0"
            icon="mdi-album"
            size="small"
            :to="{ name: 'albums', query: { search: artist.name } }"
            variant="text"
          />
          <TooltipIconButton
            :text="t('artists.viewTracks', { name: artist.name })"
            :aria-label="t('artists.viewTracks', { name: artist.name })"
            icon="mdi-music-note"
            size="small"
            :to="{ name: 'tracks', query: { search: artist.name } }"
            variant="text"
          />
        </div>
      </template>
    </v-list-item>
  </v-list>
  <EmptyCatalogState v-else :title="t('artists.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-account-music-outline" />
  <v-pagination v-if="catalog.artists.lastPage > 1" v-model="page" class="mt-6" :length="catalog.artists.lastPage" />
</template>

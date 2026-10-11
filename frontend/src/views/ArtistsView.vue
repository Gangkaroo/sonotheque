<script setup lang="ts">
import { toRefs } from 'vue'
import { useI18n } from 'vue-i18n'

import CatalogPagination from '@/components/CatalogPagination.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import { useCatalogFilters, queryInitial, queryPage, querySearch } from '@/composables/useCatalogFilters'
import { useCatalogStore } from '@/stores/catalog'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'
import { formatDateTime } from '@/utils/formatters'

const { locale, t } = useI18n()
const catalog = useCatalogStore()
const libraryRootScope = useLibraryRootScopeStore()
const { filters, searchInput: search, searchField, resultsTop, changePage, clearSearch } = useCatalogFilters({
  routeName: 'artists',
  storageKey: 'sonotheque:artist-filters',
  scope: () => libraryRootScope.selectedRootId,
  parse: input => ({
    page: queryPage(input.page),
    search: querySearch(input.search),
    initial: queryInitial(input.initial),
  }),
  load: value => { void catalog.loadArtists(value) },
})
const { page, initial } = toRefs(filters)

function selectInitial(value: string | null) {
  initial.value = value
}

function formatDate(value?: string | null) {
  return formatDateTime(value, locale.value)
}

function playCountTooltip(artist: typeof catalog.artists.items[number]) {
  return [
    t('tracks.playCountTooltip', { count: artist.playStatistics.playCount }),
    t('artists.playedTracksTooltip', {
      played: artist.playStatistics.playedTrackCount,
      total: artist.trackCount,
    }),
    t('tracks.lastPlayedAtTooltip', { value: formatDate(artist.playStatistics.lastPlayedAt) }),
  ]
}

</script>

<template>
  <PageHeader
    :title="t('artists.title')"
    :count="catalog.artistsLoading || catalog.artistsError ? undefined : catalog.artists.total"
    :description="t('artists.description')"
    icon="mdi-account-music-outline"
  />
  <v-text-field
    ref="searchField"
    v-model="search"
    class="mb-4"
    clearable
    hide-details
    prepend-inner-icon="mdi-magnify"
    :label="t('artists.search')"
    @keydown.esc.prevent.stop="clearSearch"
  />
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
  <div ref="resultsTop" class="catalog-results-anchor" />
  <CatalogPagination class="mb-4" :model-value="page" :length="catalog.artists.lastPage" @update:model-value="changePage" />
  <v-alert v-if="catalog.artistsError" type="error" variant="tonal">{{ catalog.artistsError }}</v-alert>
  <v-skeleton-loader v-else-if="catalog.artistsLoading" type="list-item-two-line@6" />
  <v-list v-else-if="catalog.artists.items.length" border rounded="xl" lines="two">
    <v-list-item
      v-for="artist in catalog.artists.items"
      :key="artist.id"
      prepend-icon="mdi-account-music-outline"
      :to="{ name: 'artist-detail', params: { id: artist.id } }"
    >
      <v-list-item-title class="font-weight-bold">{{ artist.name }}</v-list-item-title>
      <v-list-item-subtitle>{{ t('artists.albumCount', { count: artist.albumCount }) }}</v-list-item-subtitle>
      <template #append>
        <div class="d-flex align-center ga-1">
          <v-tooltip location="top">
            <template #activator="{ props }">
              <span v-bind="props" class="artist-play-count text-caption text-medium-emphasis">
                <v-icon class="play-count-icon" icon="mdi-headphones" size="x-small" />
                {{ artist.playStatistics.playCount }}
              </span>
            </template>
            <div class="play-count-tooltip">
              <div v-for="(line, index) in playCountTooltip(artist)" :key="index">{{ line }}</div>
            </div>
          </v-tooltip>
          <v-icon icon="mdi-chevron-right" size="small" />
        </div>
      </template>
    </v-list-item>
  </v-list>
  <EmptyCatalogState v-else :title="t('artists.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-account-music-outline" />
  <CatalogPagination class="mt-6" :model-value="page" :length="catalog.artists.lastPage" @update:model-value="changePage" />
</template>

<style scoped>
.artist-play-count {
  align-items: center;
  display: inline-flex;
  font-variant-numeric: tabular-nums;
  gap: 0.2rem;
  line-height: 1;
  margin-inline-end: 0.25rem;
}

.play-count-icon {
  align-self: center;
  transform: translateY(1px);
}

.play-count-tooltip {
  line-height: 1.5;
}

@media (max-width: 480px) {
  .artist-play-count {
    display: none;
  }
}
</style>

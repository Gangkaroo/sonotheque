<script setup lang="ts">
import { toRefs } from 'vue'
import { useI18n } from 'vue-i18n'

import CatalogPagination from '@/components/CatalogPagination.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useCatalogFilters, queryInitial, queryPage, querySearch } from '@/composables/useCatalogFilters'
import { useCatalogStore } from '@/stores/catalog'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'

const { t } = useI18n()
const catalog = useCatalogStore()
const libraryRootScope = useLibraryRootScopeStore()
const { filters, searchInput: search, searchField, resultsTop, changePage, clearSearch } = useCatalogFilters({
  routeName: 'musicians',
  storageKey: 'sonotheque:musician-filters',
  scope: () => libraryRootScope.selectedRootId,
  parse: input => ({
    page: queryPage(input.page),
    search: querySearch(input.search),
    initial: queryInitial(input.initial),
  }),
  load: value => { void catalog.loadMusicians(value) },
})
const { page, initial } = toRefs(filters)

function selectInitial(value: string | null) {
  initial.value = value
}
</script>

<template>
  <PageHeader
    :title="t('musicians.title')"
    :count="catalog.musiciansLoading || catalog.musiciansError ? undefined : catalog.musicians.total"
    :description="t('musicians.description')"
    icon="mdi-account-star-outline"
  />

  <v-card border class="mb-5 pa-4" rounded="xl">
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-2">
      <div>
        <div class="text-subtitle-1 font-weight-bold">{{ t('musicians.coverageTitle') }}</div>
        <div class="text-body-2 text-medium-emphasis">
          {{ t('musicians.coverageSummary', {
            checked: catalog.musicians.coverage.checkedAlbums,
            total: catalog.musicians.coverage.totalAlbums,
          }) }}
        </div>
      </div>
      <div class="d-flex flex-wrap ga-2">
        <v-chip color="primary" prepend-icon="mdi-album" variant="tonal">
          {{ t('musicians.creditedAlbums', { count: catalog.musicians.coverage.creditedAlbums }) }}
        </v-chip>
        <v-btn prepend-icon="mdi-account-question-outline" :to="{ name: 'musician-review' }" variant="tonal">
          {{ t('musicians.review') }}
        </v-btn>
      </div>
    </div>
    <v-progress-linear
      color="primary"
      height="8"
      :indeterminate="catalog.musiciansLoading"
      :model-value="catalog.musicians.coverage.percentage"
      rounded
    />
  </v-card>

  <v-text-field
    ref="searchField"
    v-model="search"
    class="mb-4"
    clearable
    hide-details
    prepend-inner-icon="mdi-magnify"
    :label="t('musicians.search')"
    @keydown.esc.prevent.stop="clearSearch"
  />
  <div class="d-flex flex-wrap ga-1 mb-6">
    <v-btn size="small" :variant="initial === null ? 'flat' : 'tonal'" @click="selectInitial(null)">
      {{ t('musicians.all') }}
    </v-btn>
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
  <CatalogPagination class="mb-4" :model-value="page" :length="catalog.musicians.lastPage" @update:model-value="changePage" />
  <v-alert v-if="catalog.musiciansError" type="error" variant="tonal">{{ catalog.musiciansError }}</v-alert>
  <v-skeleton-loader v-else-if="catalog.musiciansLoading" type="list-item-two-line@6" />
  <v-list v-else-if="catalog.musicians.items.length" border rounded="xl" lines="two">
    <v-list-item v-for="musician in catalog.musicians.items" :key="musician.id" prepend-icon="mdi-account-star-outline">
      <v-list-item-title class="font-weight-bold">
        <RouterLink class="musician-link" :to="{ name: 'musician-detail', params: { id: musician.id } }">
          {{ musician.name }}
        </RouterLink>
      </v-list-item-title>
      <v-list-item-subtitle>
        <span v-if="musician.disambiguation">{{ musician.disambiguation }} · </span>
        {{ t('musicians.creditCounts', { albums: musician.albumCount, tracks: musician.trackCount }) }}
      </v-list-item-subtitle>
      <template #append>
        <div class="d-flex align-center ga-1">
          <TooltipIconButton
            :aria-label="t('musicians.viewAlbums', { name: musician.name })"
            :disabled="musician.albumCount === 0"
            icon="mdi-album"
            size="small"
            :text="t('musicians.viewAlbums', { name: musician.name })"
            :to="{ name: 'albums', query: { musician: musician.id, musicianName: musician.name } }"
            variant="text"
          />
          <TooltipIconButton
            :aria-label="t('musicians.viewTracks', { name: musician.name })"
            :disabled="musician.trackCount === 0"
            icon="mdi-music-note"
            size="small"
            :text="t('musicians.viewTracks', { name: musician.name })"
            :to="{ name: 'tracks', query: { musician: musician.id, musicianName: musician.name } }"
            variant="text"
          />
        </div>
      </template>
    </v-list-item>
  </v-list>
  <EmptyCatalogState
    v-else
    :title="t('musicians.emptyTitle')"
    :description="t('musicians.emptyDescription')"
    icon="mdi-account-star-outline"
  />
  <CatalogPagination class="mt-6" :model-value="page" :length="catalog.musicians.lastPage" @update:model-value="changePage" />
</template>

<style scoped>
.musician-link {
  color: inherit;
  text-decoration: none;
}

.musician-link:hover {
  color: rgb(var(--v-theme-primary));
  text-decoration: underline;
}
</style>

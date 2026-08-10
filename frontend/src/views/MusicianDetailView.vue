<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import CatalogPagination from '@/components/CatalogPagination.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import { useCatalogStore } from '@/stores/catalog'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'

const { t } = useI18n()
const route = useRoute()
const catalog = useCatalogStore()
const libraryRootScope = useLibraryRootScopeStore()
const albumPage = ref(1)
const albumResultsTop = ref<HTMLElement | null>(null)

const musicianId = computed(() => Number(route.params.id))
const musician = computed(() => catalog.musicianDetail)

watch([musicianId, () => libraryRootScope.selectedRootId], ([id]) => {
  albumPage.value = 1
  void catalog.loadMusician(id)
  void catalog.loadAlbums({ musician: id, page: 1, sort: 'year_asc' })
}, { immediate: true })

watch(albumPage, (page, previousPage) => {
  if (page !== previousPage) {
    void catalog.loadAlbums({ musician: musicianId.value, page, sort: 'year_asc' })
  }
})

function changeAlbumPage(value: number) {
  if (value === albumPage.value) return

  albumPage.value = value
  void nextTick(() => albumResultsTop.value?.scrollIntoView({ behavior: 'auto', block: 'start' }))
}
</script>

<template>
  <v-btn class="mb-4" variant="text" prepend-icon="mdi-arrow-left" :to="{ name: 'musicians' }">
    {{ t('musicians.back') }}
  </v-btn>

  <v-alert v-if="catalog.musicianDetailError" type="error" variant="tonal">
    {{ catalog.musicianDetailError }}
  </v-alert>

  <v-skeleton-loader v-else-if="catalog.musicianDetailLoading" type="card, image@3" />

  <template v-else-if="musician">
    <v-card border rounded="xl" class="mb-8">
      <v-card-item>
        <template #prepend>
          <v-avatar color="primary" variant="tonal" size="88">
            <v-icon icon="mdi-account-star-outline" size="40" />
          </v-avatar>
        </template>
        <v-card-title>{{ musician.name }}</v-card-title>
        <v-card-subtitle>
          <span v-if="musician.disambiguation">{{ musician.disambiguation }} · </span>
          {{ t('musicians.detailDescription') }}
        </v-card-subtitle>
      </v-card-item>

      <v-card-text>
        <div class="musician-stat-grid">
          <div class="musician-stat-tile">
            <v-icon color="primary" icon="mdi-album" />
            <div>
              <div class="text-caption text-medium-emphasis">{{ t('musicians.albums') }}</div>
              <div class="text-h6">{{ musician.albumCount }}</div>
            </div>
          </div>
          <div class="musician-stat-tile">
            <v-icon color="primary" icon="mdi-music-note" />
            <div>
              <div class="text-caption text-medium-emphasis">{{ t('musicians.tracks') }}</div>
              <div class="text-h6">{{ musician.trackCount }}</div>
            </div>
          </div>
        </div>
      </v-card-text>
    </v-card>

    <v-card border rounded="xl">
      <v-card-title class="d-flex align-center ga-2 pa-4">
        <v-icon color="primary" icon="mdi-album" />
        {{ t('musicians.albums') }} ({{ musician.albumCount }})
      </v-card-title>
      <v-divider />
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
              :to="{ name: 'album-detail', params: { id: album.id }, query: { backMusician: musician.id } }"
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
                  <div v-if="album.primaryArtist" class="text-caption text-medium-emphasis text-truncate">
                    {{ album.primaryArtist.name }}
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    <template v-if="album.originalReleaseYear">{{ album.originalReleaseYear }} · </template>
                    {{ t('albums.trackCount', { count: album.trackCount }) }}
                  </div>
                </div>
              </div>
            </v-card>
          </v-col>
        </v-row>
        <EmptyCatalogState
          v-else
          :title="t('musicians.noAlbums')"
          :description="t('catalog.scanPrompt')"
          icon="mdi-album"
        />
        <CatalogPagination
          class="mt-4"
          :model-value="albumPage"
          :length="catalog.albums.lastPage"
          @update:model-value="changeAlbumPage"
        />
      </v-card-text>
    </v-card>
  </template>
</template>

<style scoped>
.musician-stat-grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  max-width: 520px;
}

.musician-stat-tile {
  align-items: center;
  background: rgb(var(--v-theme-surface-bright));
  border-radius: 0.75rem;
  display: flex;
  gap: 0.75rem;
  min-height: 72px;
  padding: 0.75rem;
}

.min-width-0 {
  min-width: 0;
}

@media (max-width: 480px) {
  .musician-stat-grid {
    grid-template-columns: 1fr;
  }
}
</style>

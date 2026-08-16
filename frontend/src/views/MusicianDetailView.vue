<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import CatalogPagination from '@/components/CatalogPagination.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useCatalogStore } from '@/stores/catalog'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'

const { t } = useI18n()
const route = useRoute()
const catalog = useCatalogStore()
const libraryRootScope = useLibraryRootScopeStore()
const albumPage = ref(1)
const albumResultsTop = ref<HTMLElement | null>(null)
const showAllRoles = ref(false)

const musicianId = computed(() => Number(route.params.id))
const musician = computed(() => catalog.musicianDetail)
const visibleRoles = computed(() => {
  const roles = musician.value?.roles ?? []
  return showAllRoles.value ? roles : roles.slice(0, 8)
})
const hiddenRoleCount = computed(() => Math.max(0, (musician.value?.roles.length ?? 0) - 8))
const releaseRange = computed(() => {
  const first = musician.value?.firstReleaseYear
  const last = musician.value?.lastReleaseYear
  if (!first && !last) return null
  if (!first || !last || first === last) return String(first ?? last)
  return `${first}-${last}`
})

watch([musicianId, () => libraryRootScope.selectedRootId], ([id]) => {
  albumPage.value = 1
  showAllRoles.value = false
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

function sourceLabel(source: string) {
  if (source === 'musicbrainz') return 'MusicBrainz'
  if (source === 'discogs') return 'Discogs'
  if (source === 'manual') return t('musicians.manualSource')
  return source
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
      <v-card-text class="pa-5 pa-sm-6">
        <div class="musician-header">
          <v-avatar color="primary" variant="tonal" size="96" class="musician-avatar">
            <v-icon icon="mdi-account-star-outline" size="44" />
          </v-avatar>
          <div class="min-width-0 flex-grow-1">
            <div class="d-flex flex-wrap align-center ga-2 mb-1">
              <h1 class="text-h4 text-sm-h3 font-weight-bold">{{ musician.name }}</h1>
              <TooltipIconButton
                v-if="musician.identity?.sourceUrl"
                :aria-label="t('musicians.openProvider', { provider: sourceLabel(musician.identity.provider) })"
                density="comfortable"
                icon="mdi-open-in-new"
                :href="musician.identity.sourceUrl"
                rel="noopener noreferrer"
                target="_blank"
                :text="t('musicians.openProvider', { provider: sourceLabel(musician.identity.provider) })"
                variant="text"
              />
            </div>
            <div v-if="musician.disambiguation" class="text-body-2 text-medium-emphasis mb-2">
              {{ musician.disambiguation }}
            </div>
            <div class="text-body-1 text-medium-emphasis">{{ t('musicians.detailDescription') }}</div>
            <div v-if="musician.creditedAs.length" class="text-body-2 mt-2">
              <span class="text-medium-emphasis">{{ t('musicians.creditedAs') }}:</span>
              {{ musician.creditedAs.join(', ') }}
            </div>
          </div>
        </div>

        <div class="musician-stat-grid mt-5">
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
          <div v-if="releaseRange" class="musician-stat-tile">
            <v-icon color="primary" icon="mdi-calendar-range" />
            <div>
              <div class="text-caption text-medium-emphasis">{{ t('musicians.releaseYears') }}</div>
              <div class="text-h6">{{ releaseRange }}</div>
            </div>
          </div>
        </div>

        <div v-if="musician.roles.length" class="mt-5">
          <div class="text-subtitle-2 mb-2">{{ t('musicians.roles') }}</div>
          <div class="d-flex flex-wrap ga-2">
            <v-chip v-for="role in visibleRoles" :key="role.name" color="primary" size="small" variant="tonal">
              {{ role.name }}
              <span class="text-medium-emphasis ms-1">{{ role.albumCount }}</span>
            </v-chip>
            <v-chip
              v-if="hiddenRoleCount && !showAllRoles"
              size="small"
              variant="outlined"
              @click="showAllRoles = true"
            >
              {{ t('musicians.moreRoles', { count: hiddenRoleCount }) }}
            </v-chip>
            <v-chip
              v-else-if="showAllRoles && hiddenRoleCount"
              size="small"
              variant="outlined"
              @click="showAllRoles = false"
            >
              {{ t('musicians.fewerRoles') }}
            </v-chip>
          </div>
        </div>

        <div v-if="musician.sources.length" class="d-flex flex-wrap align-center ga-2 mt-5 text-caption text-medium-emphasis">
          <span>{{ t('musicians.sources') }}:</span>
          <v-chip v-for="source in musician.sources" :key="source" size="x-small" variant="outlined">
            {{ sourceLabel(source) }}
          </v-chip>
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
              <div class="d-flex align-start pa-3 ga-3">
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
                  <div v-if="album.musicianCredits" class="d-flex flex-wrap ga-1 mt-2">
                    <v-chip
                      v-for="role in album.musicianCredits.roles.slice(0, 3)"
                      :key="role"
                      color="primary"
                      size="x-small"
                      variant="tonal"
                    >
                      {{ role }}
                    </v-chip>
                    <v-chip v-if="album.musicianCredits.roles.length > 3" size="x-small" variant="outlined">
                      +{{ album.musicianCredits.roles.length - 3 }}
                    </v-chip>
                  </div>
                  <div v-if="album.musicianCredits" class="text-caption text-medium-emphasis mt-1">
                    <span v-if="album.musicianCredits.albumWide">{{ t('musicians.albumWideCredit') }}</span>
                    <span v-else>{{ t('musicians.trackScopedCredit', { count: album.musicianCredits.trackCreditCount }) }}</span>
                    <template v-if="album.musicianCredits.guest"> · {{ t('musicians.guest') }}</template>
                    <template v-if="album.musicianCredits.additional"> · {{ t('musicians.additional') }}</template>
                    <template v-if="album.musicianCredits.sources.length">
                      · {{ album.musicianCredits.sources.map(sourceLabel).join(', ') }}
                    </template>
                  </div>
                  <div
                    v-if="album.musicianCredits?.creditedAs.length"
                    class="text-caption text-medium-emphasis text-truncate"
                  >
                    {{ t('musicians.creditedAs') }}: {{ album.musicianCredits.creditedAs.join(', ') }}
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
.musician-header {
  align-items: center;
  display: flex;
  gap: 1.25rem;
}

.musician-avatar {
  flex: 0 0 auto;
}

.musician-stat-grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  max-width: 760px;
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
  .musician-header {
    align-items: flex-start;
    flex-direction: column;
  }

  .musician-stat-grid {
    grid-template-columns: 1fr;
  }
}

@media (min-width: 481px) and (max-width: 760px) {
  .musician-stat-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>

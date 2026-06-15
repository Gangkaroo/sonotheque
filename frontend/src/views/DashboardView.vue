<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'
import { useCatalogStore } from '@/stores/catalog'

const { t } = useI18n()
const catalog = useCatalogStore()
const metrics = computed(() => [
  { key: 'artists', value: catalog.metrics.artists, icon: 'mdi-account-music-outline', to: '/artists' },
  { key: 'albums', value: catalog.metrics.albums, icon: 'mdi-album', to: '/albums' },
  { key: 'tracks', value: catalog.metrics.tracks, icon: 'mdi-music-note-outline', to: '/tracks' },
  { key: 'genres', value: catalog.metrics.genres, icon: 'mdi-tag-multiple-outline', to: '/genres' },
])

onMounted(() => catalog.loadMetrics(true))
</script>

<template>
  <PageHeader
    :title="t('dashboard.title')"
    :description="t('dashboard.description')"
    icon="mdi-view-dashboard-outline"
  />

  <v-row class="mb-6">
    <v-col v-for="metric in metrics" :key="metric.key" cols="12" sm="6" lg="3">
      <v-card
        border
        rounded="xl"
        class="metric-card pa-5"
        :to="metric.to"
        hover
      >
        <div class="d-flex align-center justify-space-between ga-4">
          <div>
            <div class="text-overline text-medium-emphasis">{{ t(`navigation.${metric.key}`) }}</div>
            <v-skeleton-loader v-if="catalog.metricsLoading" class="mt-2" type="heading" />
            <div v-else class="text-h3 font-weight-bold mt-2">{{ metric.value }}</div>
          </div>
          <v-avatar color="primary" size="56" variant="tonal">
            <v-icon :icon="metric.icon" size="30" />
          </v-avatar>
        </div>
      </v-card>
    </v-col>
  </v-row>

  <v-alert v-if="catalog.metricsError" type="error" variant="tonal">{{ catalog.metricsError }}</v-alert>
  <EmptyCatalogState
    v-else-if="!catalog.metricsLoading && !catalog.metricsHaveCatalog"
    :title="t('dashboard.emptyTitle')"
    :description="t('dashboard.emptyDescription')"
    icon="mdi-music-box-multiple-outline"
  />
</template>

<style scoped>
.metric-card {
  height: 100%;
}
</style>

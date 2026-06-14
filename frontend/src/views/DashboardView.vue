<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'

const { t } = useI18n()
</script>

<template>
  <PageHeader
    :title="t('dashboard.title')"
    :description="t('dashboard.description')"
    icon="mdi-view-dashboard-outline"
  />

  <v-row class="mb-6">
    <v-col v-for="metric in ['artists', 'albums', 'tracks', 'genres']" :key="metric" cols="12" sm="6" lg="3">
      <v-card border rounded="xl" class="pa-5">
        <div class="text-overline text-medium-emphasis">{{ t(`navigation.${metric}`) }}</div>
        <div class="text-h3 font-weight-bold mt-2">0</div>
      </v-card>
    </v-col>
  </v-row>

  <EmptyCatalogState
    :title="t('dashboard.emptyTitle')"
    :description="t('dashboard.emptyDescription')"
    icon="mdi-music-box-multiple-outline"
  />
</template>

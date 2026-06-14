<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'

const { t } = useI18n()
</script>

<template>
  <PageHeader :title="t('artists.title')" :description="t('artists.description')" icon="mdi-account-music-outline" />
  <div class="d-flex flex-wrap ga-1 mb-6">
    <v-btn v-for="letter in ['#', ...'ABCDEFGHIJKLMNOPQRSTUVWXYZ']" :key="letter" size="small" variant="tonal">
      {{ letter }}
    </v-btn>
  </div>
  <EmptyCatalogState :title="t('artists.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-account-music-outline" />
</template>

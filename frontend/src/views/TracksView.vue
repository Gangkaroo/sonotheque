<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'

const { t } = useI18n()
</script>

<template>
  <PageHeader :title="t('tracks.title')" :description="t('tracks.description')" icon="mdi-music-note-outline" />
  <EmptyCatalogState :title="t('tracks.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-music-note-outline" />
</template>

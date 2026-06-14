<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'

const { t } = useI18n()
</script>

<template>
  <PageHeader :title="t('albums.title')" :description="t('albums.description')" icon="mdi-album" />
  <EmptyCatalogState :title="t('albums.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-album" />
</template>

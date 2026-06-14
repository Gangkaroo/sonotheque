<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import PageHeader from '@/components/PageHeader.vue'

const { t } = useI18n()
</script>

<template>
  <PageHeader :title="t('genres.title')" :description="t('genres.description')" icon="mdi-tag-multiple-outline" />
  <EmptyCatalogState :title="t('genres.emptyTitle')" :description="t('catalog.scanPrompt')" icon="mdi-tag-multiple-outline" />
</template>

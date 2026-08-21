<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { formatDateTime } from '@/utils/formatters'

const props = defineProps<{
  createdAt?: string | null
  updatedAt?: string | null
}>()

const { locale, t } = useI18n()

const dates = computed(() => {
  const values = []

  if (props.createdAt) {
    values.push({
      key: 'createdAt',
      icon: 'mdi-calendar-plus',
      label: t('catalog.addedToCollection'),
      value: formatDateTime(props.createdAt, locale.value),
    })
  }

  if (props.updatedAt && isMeaningfulUpdate(props.createdAt, props.updatedAt)) {
    values.push({
      key: 'updatedAt',
      icon: 'mdi-calendar-edit',
      label: t('catalog.lastCatalogUpdate'),
      value: formatDateTime(props.updatedAt, locale.value),
    })
  }

  return values
})

function isMeaningfulUpdate(createdAt: string | null | undefined, updatedAt: string) {
  if (!createdAt) return true

  const createdTime = new Date(createdAt).getTime()
  const updatedTime = new Date(updatedAt).getTime()
  if (Number.isNaN(createdTime) || Number.isNaN(updatedTime)) return createdAt !== updatedAt

  return Math.abs(updatedTime - createdTime) > 1000
}
</script>

<template>
  <div v-if="dates.length" class="catalog-dates text-caption text-medium-emphasis">
    <span v-for="date in dates" :key="date.key" class="catalog-date">
      <v-icon :icon="date.icon" size="x-small" />
      <span>{{ date.label }}: <strong class="font-weight-medium">{{ date.value }}</strong></span>
    </span>
  </div>
</template>

<style scoped>
.catalog-dates {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 18px;
}

.catalog-date {
  align-items: center;
  display: inline-flex;
  gap: 5px;
}
</style>

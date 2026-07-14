<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import CatalogPagination from '@/components/CatalogPagination.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import {
  type LastFmDeliveryFilter,
  type LastFmDeliveryStatus,
  useLastFmDeliveriesStore,
} from '@/stores/lastFmDeliveries'
import { formatDateTime } from '@/utils/formatters'

const { t, locale } = useI18n()
const deliveryLog = useLastFmDeliveriesStore()
const selectedStatus = ref<LastFmDeliveryFilter>('all')
const page = ref(1)
const statusOptions = computed(() => [
  { title: t('settings.lastFmDeliveryStatuses.all'), value: 'all' },
  { title: t('settings.lastFmDeliveryStatuses.pending'), value: 'pending' },
  { title: t('settings.lastFmDeliveryStatuses.failed'), value: 'failed' },
  { title: t('settings.lastFmDeliveryStatuses.ignored'), value: 'ignored' },
  { title: t('settings.lastFmDeliveryStatuses.sent'), value: 'sent' },
])
const summaryStatuses: LastFmDeliveryStatus[] = ['pending', 'failed', 'ignored', 'sent']

onMounted(() => deliveryLog.load())

watch(selectedStatus, () => {
  page.value = 1
  void deliveryLog.load(1, selectedStatus.value)
})

function loadPage(nextPage: number) {
  page.value = nextPage
  void deliveryLog.load(nextPage, selectedStatus.value)
}

function refresh() {
  void deliveryLog.load(page.value, selectedStatus.value)
}

function statusColor(status: LastFmDeliveryStatus) {
  return {
    pending: 'info',
    sent: 'success',
    ignored: 'warning',
    failed: 'error',
  }[status]
}

function statusIcon(status: LastFmDeliveryStatus) {
  return {
    pending: 'mdi-clock-outline',
    sent: 'mdi-check-circle-outline',
    ignored: 'mdi-alert-circle-outline',
    failed: 'mdi-close-circle-outline',
  }[status]
}

function dateTime(value: string | null) {
  return formatDateTime(value, locale.value, t('settings.notAvailable'))
}
</script>

<template>
  <v-card border rounded="xl" class="mt-6">
    <v-card-item class="pa-6 pb-2" prepend-icon="mdi-cloud-sync-outline">
      <v-card-title>{{ t('settings.lastFmDeliveryLog') }}</v-card-title>
      <v-card-subtitle>{{ t('settings.lastFmDeliveryLogDescription') }}</v-card-subtitle>
      <template #append>
        <TooltipIconButton
          :aria-label="t('settings.refreshLastFmDeliveries')"
          icon="mdi-refresh"
          :loading="deliveryLog.loading"
          :text="t('settings.refreshLastFmDeliveries')"
          variant="text"
          @click="refresh"
        />
      </template>
    </v-card-item>

    <v-card-text class="pa-6 pt-4">
      <v-alert v-if="deliveryLog.error" class="mb-4" type="error" variant="tonal">
        {{ deliveryLog.error }}
      </v-alert>

      <div class="delivery-toolbar mb-4">
        <div class="d-flex flex-wrap ga-2">
          <v-chip
            v-for="status in summaryStatuses"
            :key="status"
            :color="statusColor(status)"
            size="small"
            variant="tonal"
          >
            {{ t(`settings.lastFmDeliveryStatuses.${status}`) }}:
            {{ deliveryLog.deliveries.summary[status] }}
          </v-chip>
        </div>
        <v-select
          v-model="selectedStatus"
          class="delivery-filter"
          density="compact"
          hide-details
          :items="statusOptions"
          :label="t('settings.lastFmDeliveryFilter')"
          variant="outlined"
        />
      </div>

      <v-skeleton-loader
        v-if="deliveryLog.loading && !deliveryLog.deliveries.items.length"
        type="list-item-three-line@3"
      />
      <v-list v-else-if="deliveryLog.deliveries.items.length" lines="three">
        <v-list-item
          v-for="delivery in deliveryLog.deliveries.items"
          :key="delivery.id"
          class="delivery-row"
        >
          <template #prepend>
            <v-icon :color="statusColor(delivery.status)" :icon="statusIcon(delivery.status)" />
          </template>
          <v-list-item-title>
            <RouterLink v-if="delivery.track" :to="`/tracks/${delivery.track.id}`">
              {{ delivery.track.title }}
            </RouterLink>
            <span v-else class="text-medium-emphasis">
              {{ t('settings.lastFmDeliveryTrackUnavailable') }}
            </span>
          </v-list-item-title>
          <v-list-item-subtitle v-if="delivery.track">
            {{ delivery.track.artists.map((artist) => artist.name).join(', ') }}
            <template v-if="delivery.track.album">
              · {{ delivery.track.album.title }}
            </template>
          </v-list-item-subtitle>
          <v-list-item-subtitle class="delivery-metadata">
            <span class="delivery-segment">
              {{ t('settings.lastFmDeliveryPlayedAt', { value: dateTime(delivery.playedAt) }) }}
            </span>
            <span v-if="delivery.scrobbledAt" class="delivery-segment">
              {{ t('settings.lastFmDeliverySentAt', { value: dateTime(delivery.scrobbledAt) }) }}
            </span>
            <span class="delivery-segment">
              {{ t('settings.lastFmDeliveryAttempts', { count: delivery.attempts }) }}
            </span>
          </v-list-item-subtitle>
          <v-list-item-subtitle v-if="delivery.error" class="text-error delivery-error">
            {{ delivery.error }}
          </v-list-item-subtitle>
          <v-list-item-subtitle v-if="delivery.ignoredCode !== null" class="text-warning">
            {{ t('settings.lastFmDeliveryIgnoredCode', { code: delivery.ignoredCode }) }}
          </v-list-item-subtitle>
          <template #append>
            <v-chip :color="statusColor(delivery.status)" size="small" variant="tonal">
              {{ t(`settings.lastFmDeliveryStatuses.${delivery.status}`) }}
            </v-chip>
          </template>
        </v-list-item>
      </v-list>
      <v-empty-state
        v-else
        icon="mdi-cloud-check-outline"
        :headline="t('settings.lastFmDeliveryEmpty')"
        :text="t('settings.lastFmDeliveryEmptyDescription')"
      />

      <CatalogPagination
        class="mt-4"
        :length="deliveryLog.deliveries.lastPage"
        :model-value="page"
        @update:model-value="loadPage"
      />
    </v-card-text>
  </v-card>
</template>

<style scoped>
.delivery-toolbar {
  align-items: center;
  display: flex;
  gap: 16px;
  justify-content: space-between;
}

.delivery-filter {
  flex: 0 1 230px;
  min-width: 180px;
}

.delivery-row :deep(.v-list-item-subtitle) {
  white-space: normal;
}

.delivery-error {
  overflow-wrap: anywhere;
}

.delivery-segment + .delivery-segment::before {
  content: ' · ';
}

@media (max-width: 600px) {
  .delivery-toolbar {
    align-items: stretch;
    flex-direction: column-reverse;
  }

  .delivery-filter {
    flex-basis: auto;
    width: 100%;
  }

  .delivery-row :deep(.v-list-item__append) {
    align-self: start;
  }
}
</style>

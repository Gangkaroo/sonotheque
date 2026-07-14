import { defineStore } from 'pinia'
import { ref } from 'vue'

import { apiRequest } from '@/api/client'
import type { CatalogPage, Track } from '@/stores/catalog'

export type LastFmDeliveryStatus = 'pending' | 'sent' | 'ignored' | 'failed'
export type LastFmDeliveryFilter = 'all' | LastFmDeliveryStatus

export interface LastFmDelivery {
  id: number
  status: LastFmDeliveryStatus
  attempts: number
  playedAt: string | null
  scrobbledAt: string | null
  error: string | null
  ignoredCode: number | null
  track: Track | null
}

export interface LastFmDeliverySummary {
  pending: number
  sent: number
  ignored: number
  failed: number
}

interface LastFmDeliveryPage extends CatalogPage<LastFmDelivery> {
  summary: LastFmDeliverySummary
}

const emptySummary = (): LastFmDeliverySummary => ({
  pending: 0,
  sent: 0,
  ignored: 0,
  failed: 0,
})

const emptyPage = (): LastFmDeliveryPage => ({
  items: [],
  total: 0,
  page: 1,
  perPage: 15,
  lastPage: 1,
  summary: emptySummary(),
})

export const useLastFmDeliveriesStore = defineStore('lastFmDeliveries', () => {
  const deliveries = ref<LastFmDeliveryPage>(emptyPage())
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function load(page = 1, status: LastFmDeliveryFilter = 'all') {
    loading.value = true
    error.value = null
    try {
      const params = new URLSearchParams({ page: String(page) })
      if (status !== 'all') params.set('status', status)
      deliveries.value = await apiRequest<LastFmDeliveryPage>(`/settings/lastfm/deliveries?${params}`)
    } catch (cause) {
      error.value = cause instanceof Error ? cause.message : 'Last.fm deliveries could not be loaded.'
    } finally {
      loading.value = false
    }
  }

  return { deliveries, loading, error, load }
})

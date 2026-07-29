import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { apiRequest } from '@/api/client'

export type ScanStatus = 'pending' | 'running' | 'completed' | 'failed' | 'cancelled'

export interface ScanIssue {
  id?: number
  code: string
  severity: 'warning' | 'error'
  message: string
  path?: string | null
  count?: number
}

export interface ScanIssueCollection {
  items: ScanIssue[]
  total: number
  totalOccurrences: number
}

export interface ScanRun {
  id: number
  libraryRootId: number
  status: ScanStatus
  trigger: string
  subtreePath: string | null
  filesDiscovered: number
  filesProcessed: number
  filesAdded: number
  filesUpdated: number
  filesRemoved: number
  warningCount: number
  errorCount: number
  startedAt: string | null
  finishedAt: string | null
  cancelRequestedAt: string | null
  summary: {
    phase?: string
    error?: string
    playStatisticsImported?: number
    unchangedFilesFastTracked?: number
    issues?: ScanIssue[]
  } | null
  createdAt: string
}

interface HydraCollection<T> { member: T[] }

function normalize(scan: ScanRun): ScanRun {
  return {
    ...scan,
    id: Number(scan.id),
    libraryRootId: Number(scan.libraryRootId),
    filesDiscovered: Number(scan.filesDiscovered),
    filesProcessed: Number(scan.filesProcessed),
    filesAdded: Number(scan.filesAdded),
    filesUpdated: Number(scan.filesUpdated),
    filesRemoved: Number(scan.filesRemoved),
    warningCount: Number(scan.warningCount),
    errorCount: Number(scan.errorCount),
  }
}

export const useScanRunsStore = defineStore('scanRuns', () => {
  const scans = ref<ScanRun[]>([])
  const loading = ref(false)
  const startingRootId = ref<number | null>(null)
  const cancellingScanId = ref<number | null>(null)
  const error = ref<string | null>(null)
  const hasActiveScans = computed(() => scans.value.some((scan) => ['pending', 'running'].includes(scan.status)))
  const recentScans = computed(() => scans.value.filter((scan) => ! ['pending', 'running'].includes(scan.status)))

  async function load({ silent = false } = {}) {
    if (!silent) loading.value = true
    error.value = null
    try {
      const collection = await apiRequest<HydraCollection<ScanRun>>('/scan_runs')
      scans.value = collection.member.map(normalize)
    } catch (cause) {
      error.value = cause instanceof Error ? cause.message : 'Unable to load scan history.'
    } finally {
      if (!silent) loading.value = false
    }
  }

  async function start(libraryRootId: number, subtreePath: string | null = null) {
    startingRootId.value = libraryRootId
    error.value = null
    try {
      const scan = normalize(await apiRequest<ScanRun>('/scan_runs', {
        method: 'POST',
        body: JSON.stringify({
          libraryRootId: String(libraryRootId),
          ...(subtreePath ? { subtreePath } : {}),
        }),
      }))
      scans.value = [scan, ...scans.value]
      return scan
    } catch (cause) {
      error.value = cause instanceof Error ? cause.message : 'Unable to start the scan.'
      throw cause
    } finally {
      startingRootId.value = null
    }
  }

  async function cancel(scanId: number) {
    cancellingScanId.value = scanId
    error.value = null
    try {
      const scan = normalize(await apiRequest<ScanRun>(`/scan_runs/${scanId}/cancel`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/merge-patch+json' },
        body: '{}',
      }))
      scans.value = scans.value.map((existing) => existing.id === scan.id ? scan : existing)
    } catch (cause) {
      error.value = cause instanceof Error ? cause.message : 'Unable to cancel the scan.'
      throw cause
    } finally {
      cancellingScanId.value = null
    }
  }

  async function loadIssues(scanId: number) {
    return apiRequest<ScanIssueCollection>(`/scan_runs/${scanId}/issues`)
  }

  async function loadOne(scanId: number) {
    return normalize(await apiRequest<ScanRun>(`/scan_runs/${scanId}`))
  }

  function latestForRoot(rootId: number) {
    return scans.value.find((scan) => scan.libraryRootId === rootId) ?? null
  }

  function activeForRoot(rootId: number) {
    return scans.value.find((scan) => (
      scan.libraryRootId === rootId && ['pending', 'running'].includes(scan.status)
    )) ?? null
  }

  function clear() {
    scans.value = []
    error.value = null
  }

  return {
    scans,
    loading,
    startingRootId,
    cancellingScanId,
    error,
    hasActiveScans,
    recentScans,
    load,
    start,
    cancel,
    loadIssues,
    loadOne,
    latestForRoot,
    activeForRoot,
    clear,
  }
})

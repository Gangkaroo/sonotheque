import {
  baseURL,
  prepareFixtureLibrary,
  startPackagedStack,
  stopPackagedStack,
  writePackagedLogs,
} from './packaged-stack'

interface LibraryRootPayload {
  id: number
}

interface ScanRunPayload {
  id: number
  status: string
  summary?: { error?: string } | null
}

interface HydraCollection<T> {
  member: T[]
}

export default async function globalSetup() {
  stopPackagedStack(true)
  await prepareFixtureLibrary()

  try {
    startPackagedStack()
    await waitForApplication()
    const root = await apiRequest<LibraryRootPayload>('/api/library_roots', {
      method: 'POST',
      body: JSON.stringify({
        name: 'Packaged Fixture',
        path: '/music/root-1',
        coverImagePaths: ['cover.jpg'],
        excludedDirectories: [],
      }),
    })
    await apiRequest('/api/settings/first-run', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/merge-patch+json' },
      body: JSON.stringify({ step: 5, completed: true }),
    })
    const scan = await apiRequest<ScanRunPayload>('/api/scan_runs', {
      method: 'POST',
      body: JSON.stringify({ libraryRootId: String(root.id) }),
    })
    await waitForScan(scan.id)
  } catch (error) {
    writePackagedLogs()
    stopPackagedStack(true)
    throw error
  }
}

async function waitForApplication() {
  const deadline = Date.now() + 180_000

  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${baseURL}/up`)
      if (response.ok) return
    } catch {
      // The packaged web container is still starting.
    }

    await delay(1_000)
  }

  throw new Error(`Packaged Sonotheque did not become ready at ${baseURL}.`)
}

async function waitForScan(scanId: number) {
  const deadline = Date.now() + 120_000

  while (Date.now() < deadline) {
    const scans = await apiRequest<HydraCollection<ScanRunPayload>>('/api/scan_runs')
    const scan = scans.member.find((candidate) => Number(candidate.id) === scanId)

    if (scan?.status === 'completed') return
    if (scan && ['cancelled', 'failed'].includes(scan.status)) {
      throw new Error(`Fixture scan ended as ${scan.status}: ${scan.summary?.error ?? 'No details available.'}`)
    }

    await delay(500)
  }

  throw new Error(`Fixture scan ${scanId} did not finish in time.`)
}

async function apiRequest<T = unknown>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/ld+json')
  if (init.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/ld+json')

  const response = await fetch(`${baseURL}${path}`, { ...init, headers })
  const body = await response.text()
  if (!response.ok) {
    throw new Error(`${init.method ?? 'GET'} ${path} returned ${response.status}: ${body}`)
  }

  return body ? JSON.parse(body) as T : undefined as T
}

function delay(milliseconds: number) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds))
}

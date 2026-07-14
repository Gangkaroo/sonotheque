interface ScanRunPayload {
  id: number
  status: string
  summary?: { error?: string } | null
}

interface HydraCollection<T> {
  member: T[]
}

export async function waitForApplication(baseURL: string, timeoutMs = 180_000) {
  const deadline = Date.now() + timeoutMs

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

export async function waitForScan(baseURL: string, scanId: number, timeoutMs = 120_000) {
  const deadline = Date.now() + timeoutMs

  while (Date.now() < deadline) {
    const scans = await apiRequest<HydraCollection<ScanRunPayload>>(baseURL, '/api/scan_runs')
    const scan = scans.member.find((candidate) => Number(candidate.id) === scanId)

    if (scan?.status === 'completed') return
    if (scan && ['cancelled', 'failed'].includes(scan.status)) {
      throw new Error(`Fixture scan ended as ${scan.status}: ${scan.summary?.error ?? 'No details available.'}`)
    }

    await delay(500)
  }

  throw new Error(`Fixture scan ${scanId} did not finish in time.`)
}

export async function apiRequest<T = unknown>(
  baseURL: string,
  path: string,
  init: RequestInit = {},
): Promise<T> {
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

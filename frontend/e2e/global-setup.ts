import {
  baseURL,
  prepareFixtureLibrary,
  startPackagedStack,
  stopPackagedStack,
  writePackagedLogs,
} from './packaged-stack'
import { apiRequest, waitForApplication, waitForScan } from './packaged-api'

interface LibraryRootPayload {
  id: number
}

interface ScanRunPayload { id: number }

export default async function globalSetup() {
  stopPackagedStack(true)
  await prepareFixtureLibrary()

  try {
    startPackagedStack()
    await waitForApplication(baseURL)
    const root = await apiRequest<LibraryRootPayload>(baseURL, '/api/library_roots', {
      method: 'POST',
      body: JSON.stringify({
        name: 'Packaged Fixture',
        path: '/music/root-1',
        coverImagePaths: ['cover.jpg'],
        excludedDirectories: [],
      }),
    })
    await apiRequest(baseURL, '/api/settings/first-run', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/merge-patch+json' },
      body: JSON.stringify({ step: 5, completed: true }),
    })
    const scan = await apiRequest<ScanRunPayload>(baseURL, '/api/scan_runs', {
      method: 'POST',
      body: JSON.stringify({ libraryRootId: String(root.id) }),
    })
    await waitForScan(baseURL, scan.id)
  } catch (error) {
    writePackagedLogs()
    stopPackagedStack(true)
    throw error
  }
}

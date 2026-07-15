/// <reference lib="dom" />

import { expect, test } from '@playwright/test'

import { apiRequest, waitForApplication, waitForScan } from './packaged-api'
import {
  baselineSourceDirectory,
  currentMigrationStatus,
  currentSourceDirectory,
  prepareUpgradeFixture,
  readStorageSentinel,
  removeUpgradeFixture,
  startBaselineStack,
  startCurrentStack,
  stopUpgradeStack,
  upgradeBaseURL,
  writeStorageSentinel,
  writeUpgradeLogs,
} from './packaged-upgrade'

interface LibraryRootPayload {
  id: number
  name: string
  path: string
}

interface ScanRunPayload { id: number }
interface CatalogTrack { id: number; title: string; album: { id: number } }
interface CatalogCollection<T> { items: T[]; total: number }
interface IdentifiedPayload { id: number }
interface HydraCollection<T> { member: T[] }

test('upgrades a populated v0.1.0 package without losing application state', async ({ page }) => {
  test.setTimeout(900_000)
  stopUpgradeStack(true, true)
  await prepareUpgradeFixture()
  let activeSource = baselineSourceDirectory()

  try {
    startBaselineStack()
    await waitForApplication(upgradeBaseURL)
    const fixture = await seedBaselineState()
    writeStorageSentinel(activeSource)

    stopUpgradeStack(false)
    activeSource = currentSourceDirectory()
    startCurrentStack()
    await waitForApplication(upgradeBaseURL)

    await verifyUpgradedState(fixture)
    expect(readStorageSentinel()).toBe('v0.1.0-storage')
    expect(currentMigrationStatus()).toContain('2026_07_13_160000')

    await page.goto('/playlists')
    await expect(page.getByText('Upgrade Fixture Playlist', { exact: true })).toBeVisible()
  } catch (error) {
    writeUpgradeLogs(activeSource)
    throw error
  } finally {
    stopUpgradeStack(true, true)
    await removeUpgradeFixture()
  }
})

async function seedBaselineState() {
  const root = await apiRequest<LibraryRootPayload>(upgradeBaseURL, '/api/library_roots', {
    method: 'POST',
    body: JSON.stringify({
      name: 'Upgrade Fixture Root',
      path: '/music/root-1',
      coverImagePaths: ['cover.jpg'],
      excludedDirectories: [],
    }),
  })
  await apiRequest(upgradeBaseURL, '/api/settings/first-run', {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/merge-patch+json' },
    body: JSON.stringify({ step: 5, completed: true }),
  })
  const scan = await apiRequest<ScanRunPayload>(upgradeBaseURL, '/api/scan_runs', {
    method: 'POST',
    body: JSON.stringify({ libraryRootId: String(root.id) }),
  })
  await waitForScan(upgradeBaseURL, scan.id)

  const tracks = await apiRequest<CatalogCollection<CatalogTrack>>(upgradeBaseURL, '/api/catalog/tracks')
  expect(tracks.total).toBe(1)
  const track = tracks.items[0]
  expect(track).toBeDefined()

  const folder = await apiRequest<IdentifiedPayload>(upgradeBaseURL, '/api/playlist-folders', {
    method: 'POST',
    body: JSON.stringify({ name: 'Upgrade Fixture Folder' }),
  })
  const playlist = await apiRequest<IdentifiedPayload>(upgradeBaseURL, '/api/playlists', {
    method: 'POST',
    body: JSON.stringify({
      name: 'Upgrade Fixture Playlist',
      description: 'Preserved from v0.1.0',
      folderId: folder.id,
      trackIds: [track.id],
    }),
  })
  await apiRequest(upgradeBaseURL, `/api/favorites/tracks/${track.id}`, { method: 'POST' })
  await apiRequest(upgradeBaseURL, `/api/favorites/albums/${track.album.id}`, { method: 'POST' })
  await apiRequest(upgradeBaseURL, `/api/albums/${track.album.id}/personal-metadata`, {
    method: 'PATCH',
    body: JSON.stringify({
      purchaseSource: 'Upgrade fixture shop',
      purchaseDate: '2024-05-17',
      hasPhysicalCopy: true,
      physicalFormat: 'cd',
      notes: 'Created before package upgrade',
    }),
  })
  await apiRequest(upgradeBaseURL, '/api/settings/online-enrichment', {
    method: 'PATCH',
    body: JSON.stringify({ informationEnabled: true, lyricsEnabled: false }),
  })

  return { root, track, folder, playlist }
}

async function verifyUpgradedState(fixture: Awaited<ReturnType<typeof seedBaselineState>>) {
  const roots = await apiRequest<HydraCollection<LibraryRootPayload>>(upgradeBaseURL, '/api/library_roots')
  expect(roots.member).toContainEqual(expect.objectContaining({
    id: fixture.root.id,
    name: 'Upgrade Fixture Root',
    path: '/music/root-1',
  }))

  const tracks = await apiRequest<CatalogCollection<CatalogTrack>>(upgradeBaseURL, '/api/catalog/tracks')
  expect(tracks.items).toContainEqual(expect.objectContaining({ id: fixture.track.id }))

  const favorites = await apiRequest<{ tracks: number[]; albums: number[] }>(upgradeBaseURL, '/api/favorites')
  expect(favorites.tracks).toContain(fixture.track.id)
  expect(favorites.albums).toContain(fixture.track.album.id)

  const folders = await apiRequest<{ items: Array<{ id: number; name: string }> }>(upgradeBaseURL, '/api/playlist-folders')
  expect(folders.items).toContainEqual(expect.objectContaining({
    id: fixture.folder.id,
    name: 'Upgrade Fixture Folder',
  }))
  const playlist = await apiRequest<{
    id: number
    description: string
    trackCount: number
    items: Array<{ track: { id: number } }>
  }>(upgradeBaseURL, `/api/playlists/${fixture.playlist.id}`)
  expect(playlist).toEqual(expect.objectContaining({
    id: fixture.playlist.id,
    description: 'Preserved from v0.1.0',
    trackCount: 1,
  }))
  expect(playlist.items[0]?.track.id).toBe(fixture.track.id)

  const album = await apiRequest<{
    personalMetadata: {
      purchaseSource: string
      purchaseDate: string
      hasPhysicalCopy: boolean
      physicalFormat: string
      notes: string
      ownedCopies: Array<{
        isPhysical: boolean
        physicalFormat: string | null
        purchaseSource: string | null
        purchaseDate: string | null
      }>
    }
  }>(upgradeBaseURL, `/api/catalog/albums/${fixture.track.album.id}`)
  expect(album.personalMetadata).toEqual(expect.objectContaining({
    purchaseSource: 'Upgrade fixture shop',
    purchaseDate: '2024-05-17',
    hasPhysicalCopy: true,
    physicalFormat: 'cd',
    notes: 'Created before package upgrade',
    ownedCopies: [expect.objectContaining({
      isPhysical: true,
      physicalFormat: 'cd',
      purchaseSource: 'Upgrade fixture shop',
      purchaseDate: '2024-05-17',
    })],
  }))

  const setup = await apiRequest<{ completed: boolean; step: number }>(upgradeBaseURL, '/api/settings/first-run')
  expect(setup).toEqual(expect.objectContaining({ completed: true, step: 5 }))
  const enrichment = await apiRequest<{ informationEnabled: boolean; lyricsEnabled: boolean }>(
    upgradeBaseURL,
    '/api/settings/online-enrichment',
  )
  expect(enrichment).toEqual(expect.objectContaining({ informationEnabled: true, lyricsEnabled: false }))

  const folderResponse = await apiRequest<{ path: string | null }>(
    upgradeBaseURL,
    `/api/catalog/library-roots/${fixture.root.id}/folders`,
  )
  expect(folderResponse.path).toBeNull()
}

/// <reference lib="dom" />

import { expect, test, type Page } from '@playwright/test'

import {
  directoryRow,
  openPackagedFixtureAlbum,
  selectPackagedFixtureRoot,
} from './packaged-fixture'

const runningScan = {
  id: 9001,
  libraryRootId: 0,
  status: 'running',
  trigger: 'manual',
  subtreePath: 'Fixture Artist',
  filesDiscovered: 12,
  filesProcessed: 4,
  filesAdded: 2,
  filesUpdated: 1,
  filesRemoved: 0,
  warningCount: 0,
  errorCount: 0,
  startedAt: '2026-07-14T10:00:00Z',
  finishedAt: null,
  cancelRequestedAt: null,
  summary: { phase: 'processing' },
  createdAt: '2026-07-14T10:00:00Z',
}

test.beforeEach(async ({ page, request }) => {
  await selectPackagedFixtureRoot(page, request)
})

test('navigates a scanned folder tree and shows its indexed track', async ({ page }) => {
  await openPackagedFixtureAlbum(page)
  await expect(page.getByRole('heading', { name: 'Folders' })).toBeVisible()
  await expect(page).toHaveURL(/path=Fixture\+Artist\/Fixture\+Album/)
  await expect(page.getByText('01 - Fixture Track', { exact: true })).toBeVisible()
  await expect(
    directoryRow(page, '01 - Fixture Track').getByRole('button', { name: 'Play', exact: true }),
  ).toBeEnabled()

  await page.getByText('Parent folder', { exact: true }).click()
  await expect(page).toHaveURL(/path=Fixture\+Artist/)
})

test('confirms a large folder action before loading all tracks', async ({ page }) => {
  await page.route('**/api/catalog/library-roots/*/folder-tracks?**', async (route) => {
    const url = new URL(route.request().url())
    if (url.searchParams.get('confirmationThreshold') === '500') {
      await route.fulfill({
        contentType: 'application/ld+json',
        json: {
          path: 'Fixture Artist',
          total: 500,
          requiresConfirmation: true,
          tracks: [],
        },
      })
      return
    }

    await route.continue()
  })

  await page.goto('/folders')
  await directoryRow(page, 'Fixture Artist').getByRole('button', { name: 'Queue folder' }).click()

  const dialog = page.getByRole('dialog').filter({ hasText: 'Large folder action' })
  await expect(dialog).toContainText('Add all 500 indexed tracks in Fixture Artist to the queue?')
  await dialog.getByRole('button', { name: 'Cancel' }).click()
  await expect(dialog).toBeHidden()
})

test('shows subtree scan progress and allows cancellation', async ({ page }) => {
  await mockScanLifecycle(page)
  await page.goto('/folders')

  await directoryRow(page, 'Fixture Artist').getByRole('button', { name: 'Rescan folder' }).click()
  await expect(page.getByText('Scanning Fixture Artist', { exact: true })).toBeVisible()
  await expect(page.getByText('4/12 files', { exact: true })).toBeVisible()

  await page.getByRole('button', { name: 'Cancel scan' }).click()
  await expect(page.getByText('Scan cancellation requested.', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Cancel scan' })).toBeHidden()
})

async function mockScanLifecycle(page: Page) {
  let started = false

  await page.route('**/api/scan_runs**', async (route) => {
    const request = route.request()
    const url = new URL(request.url())

    if (request.method() === 'GET' && url.pathname === '/api/scan_runs') {
      await route.fulfill({
        contentType: 'application/ld+json',
        json: { member: started ? [{ ...runningScan }] : [] },
      })
      return
    }

    if (request.method() === 'POST' && url.pathname === '/api/scan_runs') {
      const payload = request.postDataJSON() as { libraryRootId: string; subtreePath?: string }
      expect(payload.subtreePath).toBe('Fixture Artist')
      started = true
      await route.fulfill({
        contentType: 'application/ld+json',
        json: { ...runningScan, libraryRootId: Number(payload.libraryRootId) },
      })
      return
    }

    if (request.method() === 'PATCH' && url.pathname === `/api/scan_runs/${runningScan.id}/cancel`) {
      started = false
      await route.fulfill({
        contentType: 'application/ld+json',
        json: {
          ...runningScan,
          status: 'cancelled',
          cancelRequestedAt: '2026-07-14T10:01:00Z',
          finishedAt: '2026-07-14T10:01:00Z',
        },
      })
      return
    }

    await route.continue()
  })
}

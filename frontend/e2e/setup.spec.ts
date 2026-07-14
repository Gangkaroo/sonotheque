/// <reference lib="dom" />

import { expect, test } from '@playwright/test'

import { apiRequest, waitForScan } from './packaged-api'
import { baseURL } from './packaged-stack'

interface ScanRunPayload { id: number }

interface LibraryRootPayload {
  id: number
  name: string
  path: string
  coverImagePaths: string[]
}

interface HydraCollection<T> { member: T[] }

test('configures and scans a new packaged installation', async ({ page }) => {
  test.setTimeout(180_000)
  await page.goto('/')

  await expect(page).toHaveURL(/\/setup$/)
  await expect(page.getByRole('heading', { name: 'Set up Sonotheque' })).toBeVisible()
  await expect(page.getByText('System health', { exact: true })).toBeVisible()

  await page.getByRole('button', { name: 'Continue' }).click()
  await expect(page.getByText('Add at least one library root to continue.', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Continue' })).toBeDisabled()

  await page.getByRole('button', { name: 'Add library root' }).click()
  const rootDialog = page.getByRole('dialog').filter({ hasText: 'Add library root' })
  await expect(rootDialog).toBeVisible()
  await rootDialog.getByLabel('Display name').fill('Packaged Fixture')
  await rootDialog.getByLabel('Folder path').fill('/music/root-1')
  await rootDialog.getByRole('textbox', { name: 'Cover path 1', exact: true }).fill('cover.jpg')
  await rootDialog.getByRole('button', { name: 'Add cover path', exact: true }).click()
  await rootDialog.getByRole('textbox', { name: 'Cover path 2', exact: true }).fill('Disc 1/cover.jpg')
  await rootDialog.getByRole('button', { name: 'Add root' }).click()

  await expect(rootDialog).toBeHidden()
  await expect(page.getByText('Packaged Fixture', { exact: true })).toBeVisible()
  await expect(page.getByText('/music/root-1', { exact: true })).toBeVisible()

  await page.getByRole('button', { name: 'Continue' }).click()
  await expect(page.getByText('Listening statistics', { exact: true })).toBeVisible()

  const statisticsSwitch = page.getByRole('checkbox', {
    name: 'Synchronize listening statistics with file tags',
  })
  await expect(statisticsSwitch).not.toBeChecked()
  const statisticsResponse = page.waitForResponse((response) => (
    response.url().endsWith('/api/settings/playback-statistics')
      && response.request().method() === 'PATCH'
  ))
  await statisticsSwitch.click()
  await statisticsResponse
  await expect(statisticsSwitch).toBeChecked()

  const backupSwitch = page.getByRole('checkbox', { name: 'Create backups before metadata edits' })
  await backupSwitch.click()
  await page.getByLabel('Retention in days').fill('45')
  const backupResponse = page.waitForResponse((response) => (
    response.url().endsWith('/api/settings/metadata-backups')
      && response.request().method() === 'PATCH'
  ))
  await page.getByRole('button', { name: 'Save backup settings' }).click()
  await backupResponse
  await expect(page.getByText('Metadata backup settings saved.', { exact: true })).toBeVisible()

  await page.reload()
  await expect(page.getByText('Listening statistics', { exact: true })).toBeVisible()
  await expect(page.getByRole('checkbox', {
    name: 'Synchronize listening statistics with file tags',
  })).toBeChecked()
  await expect(page.getByRole('checkbox', { name: 'Create backups before metadata edits' })).toBeChecked()
  await expect(page.getByLabel('Retention in days')).toHaveValue('45')

  await page.getByRole('button', { name: 'Continue' }).click()
  await expect(page.getByText('Online content', { exact: true })).toBeVisible()
  await expect(page.getByRole('checkbox', { name: 'Artist and album information' })).not.toBeChecked()
  await expect(page.getByRole('checkbox', { name: 'Lyrics' })).not.toBeChecked()

  await page.getByRole('button', { name: 'Continue' }).click()
  await expect(page.getByText('Build your catalog', { exact: true })).toBeVisible()

  const scanResponse = page.waitForResponse((response) => (
    response.url().endsWith('/api/scan_runs') && response.request().method() === 'POST'
  ))
  await page.getByRole('button', { name: 'Start scan' }).click()
  const scan = await (await scanResponse).json() as ScanRunPayload
  await waitForScan(baseURL, scan.id)

  await page.getByRole('button', { name: 'Finish setup' }).click()
  await expect(page).toHaveURL('/')
  await expect(page.getByRole('heading', { name: 'Your library' })).toBeVisible()

  const setup = await apiRequest<{ completed: boolean; step: number; hasLibraryRoots: boolean }>(
    baseURL,
    '/api/settings/first-run',
  )
  expect(setup).toEqual({ completed: true, step: 5, hasLibraryRoots: true })

  const roots = await apiRequest<HydraCollection<LibraryRootPayload>>(baseURL, '/api/library_roots')
  expect(roots.member).toContainEqual(expect.objectContaining({
    name: 'Packaged Fixture',
    path: '/music/root-1',
    coverImagePaths: ['cover.jpg', 'Disc 1/cover.jpg'],
  }))

  const tracks = await apiRequest<{ total: number }>(baseURL, '/api/catalog/tracks')
  expect(tracks.total).toBe(2)
})

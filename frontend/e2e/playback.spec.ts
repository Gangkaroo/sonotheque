/// <reference lib="dom" />

import { expect, test, type Locator } from '@playwright/test'

import {
  directoryRow,
  openPackagedFixtureAlbum,
  selectPackagedFixtureRoot,
} from './packaged-fixture'

test.beforeEach(async ({ page, request }) => {
  await selectPackagedFixtureRoot(page, request)
})

test('streams, seeks, pauses, and resumes a packaged track', async ({ page }) => {
  await openPackagedFixtureAlbum(page)
  const streamResponse = page.waitForResponse((response) => (
    response.url().includes('/api/tracks/')
    && response.url().endsWith('/stream')
    && [200, 206].includes(response.status())
  ))

  await directoryRow(page, '01 - Fixture Track').getByRole('button', { name: 'Play', exact: true }).click()
  const response = await streamResponse
  expect(response.headers()['accept-ranges']).toBe('bytes')

  const player = page.locator('.player-footer')
  const audio = player.locator('audio')
  await expect(player.getByText('01 - Fixture Track', { exact: true })).toBeVisible()
  await expect.poll(() => mediaState(audio, 'paused')).toBe(false)
  await expect.poll(() => mediaTime(audio)).toBeGreaterThan(0.25)

  await seekToSecond(player, 6)
  await expect.poll(() => mediaTime(audio)).toBeGreaterThanOrEqual(5)

  await player.getByRole('button', { name: 'Pause', exact: true }).click()
  await expect.poll(() => mediaState(audio, 'paused')).toBe(true)
  const pausedAt = await mediaTime(audio)
  await page.waitForTimeout(700)
  expect(Math.abs(await mediaTime(audio) - pausedAt)).toBeLessThan(0.25)

  await player.getByRole('button', { name: 'Play', exact: true }).click()
  await expect.poll(() => mediaState(audio, 'paused')).toBe(false)
  await expect.poll(() => mediaTime(audio)).toBeGreaterThan(pausedAt + 0.25)
})

test('progresses the queue after seeking and restores playback after refresh', async ({ page }) => {
  await openPackagedFixtureAlbum(page)
  await page.getByRole('button', { name: 'Play folder', exact: true }).click()

  const player = page.locator('.player-footer')
  const audio = player.locator('audio')
  await expect(player.getByText('01 - Fixture Track', { exact: true })).toBeVisible()
  await expect(player.getByRole('button', { name: 'Next track' })).toBeEnabled()

  await seekToSecond(player, 10)
  await expect.poll(() => mediaTime(audio)).toBeGreaterThanOrEqual(9)
  await expect(player.getByText('02 - Second Fixture Track', { exact: true })).toBeVisible({ timeout: 5_000 })
  await expect.poll(() => mediaState(audio, 'paused')).toBe(false)
  await expect.poll(() => mediaTime(audio)).toBeGreaterThan(0.25)

  await seekToSecond(player, 5)
  await expect.poll(() => mediaTime(audio)).toBeGreaterThanOrEqual(4)
  const positionBeforeRefresh = await mediaTime(audio)
  await page.reload()

  const restoredPlayer = page.locator('.player-footer')
  const restoredAudio = restoredPlayer.locator('audio')
  await expect(restoredPlayer.getByText('02 - Second Fixture Track', { exact: true })).toBeVisible()
  await expect.poll(() => mediaTime(restoredAudio)).toBeGreaterThanOrEqual(positionBeforeRefresh - 1.5)
  await expect.poll(() => mediaState(restoredAudio, 'paused')).toBe(false)

  await restoredPlayer.getByRole('button', { name: 'Previous track' }).click()
  await expect(restoredPlayer.getByText('01 - Fixture Track', { exact: true })).toBeVisible()
  await expect.poll(() => mediaState(restoredAudio, 'paused')).toBe(false)

  await restoredPlayer.getByRole('button', { name: 'Next track' }).click()
  await expect(restoredPlayer.getByText('02 - Second Fixture Track', { exact: true })).toBeVisible()
  await expect.poll(() => mediaState(restoredAudio, 'paused')).toBe(false)
})

async function seekToSecond(player: Locator, second: number) {
  const slider = player.getByRole('slider', { name: 'Seek in track' })
  await expect(slider).toBeEnabled()
  await slider.focus()
  await slider.press('Home')
  for (let index = 0; index < second; index += 1) await slider.press('ArrowRight')
}

async function mediaTime(audio: Locator) {
  return audio.evaluate((element: HTMLAudioElement) => element.currentTime)
}

async function mediaState(audio: Locator, property: 'paused') {
  return audio.evaluate((element: HTMLAudioElement, key) => element[key], property)
}

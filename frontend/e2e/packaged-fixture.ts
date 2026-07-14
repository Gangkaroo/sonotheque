import { expect, type APIRequestContext, type Page } from '@playwright/test'

interface LibraryRootPayload {
  id: number
  name: string
}

interface HydraCollection<T> {
  member: T[]
}

export async function selectPackagedFixtureRoot(page: Page, request: APIRequestContext) {
  const response = await request.get('/api/library_roots')
  expect(response.ok()).toBeTruthy()
  const roots = await response.json() as HydraCollection<LibraryRootPayload>
  const root = roots.member.find((candidate) => candidate.name === 'Packaged Fixture')
  expect(root).toBeDefined()

  await page.addInitScript((rootId) => {
    window.sessionStorage.setItem('sonotheque.active-library-root', String(rootId))
  }, root!.id)
}

export async function openPackagedFixtureAlbum(page: Page) {
  await page.goto('/folders')
  await directoryRow(page, 'Fixture Artist').click()
  await directoryRow(page, 'Fixture Album').click()
}

export function directoryRow(page: Page, name: string) {
  return page.locator('.folder-row').filter({ hasText: name }).first()
}

import { defineConfig, devices } from '@playwright/test'

const baseURL = process.env.SONOTHEQUE_UPGRADE_BASE_URL ?? 'http://127.0.0.1:18081'

export default defineConfig({
  testDir: './e2e',
  testMatch: 'upgrade.spec.ts',
  fullyParallel: false,
  workers: 1,
  timeout: 900_000,
  expect: { timeout: 10_000 },
  reporter: [['list']],
  use: {
    baseURL,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})

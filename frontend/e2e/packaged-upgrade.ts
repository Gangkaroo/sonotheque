import { execFileSync } from 'node:child_process'
import { mkdir, rm, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

import { createSilentWav } from './packaged-stack'

export const upgradeBaseURL = process.env.SONOTHEQUE_UPGRADE_BASE_URL ?? 'http://127.0.0.1:18081'
export const upgradeBaseline = process.env.SONOTHEQUE_UPGRADE_BASELINE ?? 'v0.1.0'

const frontendDirectory = fileURLToPath(new URL('../', import.meta.url))
const repositoryDirectory = path.resolve(frontendDirectory, '..')
const runtimeDirectory = path.join(frontendDirectory, 'e2e', 'runtime', 'upgrade')
const baselineDirectory = path.join(runtimeDirectory, 'baseline')
const archivePath = path.join(runtimeDirectory, 'baseline.tar')
const musicRoot = path.join(runtimeDirectory, 'music-root')
const projectName = 'sonotheque-upgrade-e2e'
const cleanupOptions = {
  force: true,
  maxRetries: 20,
  recursive: true,
  retryDelay: 250,
} as const

const composeEnvironment = {
  ...process.env,
  APP_HTTP_BIND: '127.0.0.1',
  APP_HTTP_PORT: new URL(upgradeBaseURL).port || '18081',
  APP_KEY: 'base64:REREREREREREREREREREREREREREREREREREREREREQ=',
  APP_URL: upgradeBaseURL,
  CACHE_STORE: 'file',
  MUSIC_LIBRARY_ADMIN_TOKEN: '',
  MUSIC_LIBRARY_ALLOWED_ORIGINS: '',
  MUSIC_LIBRARY_LAN_ENABLED: 'false',
  MUSIC_LIBRARY_LOCAL_PROXY_ENABLED: 'true',
  MUSIC_LIBRARY_ROOT_1: musicRoot,
  MUSIC_LIBRARY_TRUSTED_HOSTS: 'localhost,127.0.0.1,::1',
  POSTGRES_DB: 'sonotheque_upgrade_e2e',
  POSTGRES_PASSWORD: 'sonotheque-upgrade-password',
  POSTGRES_USER: 'sonotheque',
  SONOTHEQUE_ADMIN_TOKEN: '',
  SONOTHEQUE_ALLOWED_ORIGINS: '',
  SONOTHEQUE_LAN_ENABLED: 'false',
  SONOTHEQUE_LOCAL_PROXY_ENABLED: 'true',
  SONOTHEQUE_ROOT_1: musicRoot,
  SONOTHEQUE_TRUSTED_HOSTS: 'localhost,127.0.0.1,::1',
}

export async function prepareUpgradeFixture() {
  assertRuntimeDirectory()
  await rm(runtimeDirectory, cleanupOptions)
  await mkdir(baselineDirectory, { recursive: true })

  execFileSync('git', ['rev-parse', '--verify', `refs/tags/${upgradeBaseline}`], {
    cwd: repositoryDirectory,
    stdio: 'ignore',
  })
  execFileSync('git', ['archive', '--format=tar', `--output=${archivePath}`, upgradeBaseline], {
    cwd: repositoryDirectory,
    stdio: 'inherit',
  })
  execFileSync('tar', ['-xf', archivePath, '-C', baselineDirectory], {
    cwd: repositoryDirectory,
    stdio: 'inherit',
  })

  const albumDirectory = path.join(musicRoot, 'Upgrade Artist', 'Upgrade Album')
  await mkdir(albumDirectory, { recursive: true })
  await writeFile(path.join(albumDirectory, '01 - Upgrade Track.wav'), createSilentWav())
}

export function startBaselineStack() {
  startStack(baselineDirectory, ['queue'])
}

export function startCurrentStack() {
  startStack(repositoryDirectory, [
    'queue-default',
    'queue-scans',
    'queue-analysis',
  ])
}

export function stopUpgradeStack(removeVolumes: boolean, ignoreErrors = false) {
  const arguments_ = ['down', '--remove-orphans', '--timeout', '5']
  if (removeVolumes) arguments_.splice(1, 0, '--volumes')

  try {
    compose(repositoryDirectory, arguments_)
  } catch (error) {
    if (!ignoreErrors) throw error
  }
}

export function writeUpgradeLogs(sourceDirectory: string) {
  try {
    compose(sourceDirectory, ['logs', '--no-color', '--tail', '200'])
  } catch {
    // Preserve the original upgrade failure when Docker cannot provide logs.
  }
}

export function writeStorageSentinel(sourceDirectory: string) {
  compose(sourceDirectory, [
    'exec', '-T', 'backend', 'sh', '-c',
    "printf 'v0.1.0-storage' > storage/app/private/upgrade-sentinel.txt",
  ])
}

export function readStorageSentinel() {
  return composeOutput(repositoryDirectory, [
    'exec', '-T', 'backend', 'cat', 'storage/app/private/upgrade-sentinel.txt',
  ]).trim()
}

export function currentMigrationStatus() {
  return composeOutput(repositoryDirectory, [
    'exec', '-T', 'backend', 'php', 'artisan', 'migrate:status', '--no-ansi',
  ])
}

export async function removeUpgradeFixture() {
  assertRuntimeDirectory()
  await rm(runtimeDirectory, cleanupOptions)
}

export function baselineSourceDirectory() {
  return baselineDirectory
}

export function currentSourceDirectory() {
  return repositoryDirectory
}

function startStack(sourceDirectory: string, workerServices: string[]) {
  compose(sourceDirectory, ['build'])
  compose(sourceDirectory, ['up', '-d', 'postgres'])
  compose(sourceDirectory, [
    'up',
    '--force-recreate',
    '--abort-on-container-exit',
    '--exit-code-from',
    'migrate',
    'migrate',
  ])
  compose(sourceDirectory, ['up', '-d', '--wait', 'backend'])
  compose(sourceDirectory, [
    'up',
    '-d',
    ...workerServices,
    'scheduler',
    'web',
  ])
}

function compose(sourceDirectory: string, arguments_: string[]) {
  execFileSync('docker', composeArguments(sourceDirectory, arguments_), {
    cwd: sourceDirectory,
    env: composeEnvironment,
    stdio: 'inherit',
  })
}

function composeOutput(sourceDirectory: string, arguments_: string[]) {
  return execFileSync('docker', composeArguments(sourceDirectory, arguments_), {
    cwd: sourceDirectory,
    encoding: 'utf8',
    env: composeEnvironment,
  })
}

function composeArguments(sourceDirectory: string, arguments_: string[]) {
  return [
    'compose',
    '--project-name', projectName,
    '--file', path.join(sourceDirectory, 'compose.packaged.yaml'),
    ...arguments_,
  ]
}

function assertRuntimeDirectory() {
  const expectedSuffix = path.join('frontend', 'e2e', 'runtime', 'upgrade')
  if (!runtimeDirectory.endsWith(expectedSuffix)) {
    throw new Error(`Refusing to modify unexpected upgrade runtime path: ${runtimeDirectory}`)
  }
}

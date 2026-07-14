import { execFileSync } from 'node:child_process'
import { mkdir, rm, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

export const baseURL = process.env.SONOTHEQUE_E2E_BASE_URL ?? 'http://127.0.0.1:18080'

const frontendDirectory = fileURLToPath(new URL('../', import.meta.url))
const repositoryDirectory = path.resolve(frontendDirectory, '..')
const composeFile = path.join(repositoryDirectory, 'compose.packaged.yaml')
const runtimeDirectory = path.join(frontendDirectory, 'e2e', 'runtime')
const musicRoot = path.join(runtimeDirectory, 'music-root')
const projectName = 'sonotheque-e2e'

const composeEnvironment = {
  ...process.env,
  APP_HTTP_BIND: '127.0.0.1',
  APP_HTTP_PORT: new URL(baseURL).port || '18080',
  APP_KEY: 'base64:Q0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0NDQ0M=',
  APP_URL: baseURL,
  CACHE_STORE: 'file',
  POSTGRES_DB: 'sonotheque_e2e',
  POSTGRES_PASSWORD: 'sonotheque-e2e-password',
  POSTGRES_USER: 'sonotheque',
  SONOTHEQUE_ADMIN_TOKEN: '',
  SONOTHEQUE_ALLOWED_ORIGINS: '',
  SONOTHEQUE_LAN_ENABLED: 'false',
  SONOTHEQUE_LOCAL_PROXY_ENABLED: 'true',
  SONOTHEQUE_ROOT_1: musicRoot,
  SONOTHEQUE_TRUSTED_HOSTS: 'localhost,127.0.0.1,::1',
}

export async function prepareFixtureLibrary() {
  assertRuntimeDirectory()
  await rm(runtimeDirectory, { force: true, recursive: true })

  const albumDirectory = path.join(musicRoot, 'Fixture Artist', 'Fixture Album')
  await mkdir(albumDirectory, { recursive: true })
  await writeFile(path.join(albumDirectory, '01 - Fixture Track.wav'), silentWav())
}

export function startPackagedStack() {
  compose(['build'])
  compose(['up', '-d', 'postgres'])
  compose([
    'up',
    '--force-recreate',
    '--abort-on-container-exit',
    '--exit-code-from',
    'migrate',
    'migrate',
  ])
  compose(['up', '-d', 'backend', 'queue', 'scheduler', 'web'])
}

export function stopPackagedStack(ignoreErrors = false) {
  try {
    compose(['down', '--volumes', '--remove-orphans', '--timeout', '5'])
  } catch (error) {
    if (!ignoreErrors) throw error
  }
}

export async function removeFixtureLibrary() {
  assertRuntimeDirectory()
  await rm(runtimeDirectory, { force: true, recursive: true })
}

export function writePackagedLogs() {
  try {
    compose(['logs', '--no-color', '--tail', '200'])
  } catch {
    // Preserve the original setup error when Docker cannot provide logs.
  }
}

function compose(arguments_: string[]) {
  execFileSync('docker', [
    'compose',
    '--project-name', projectName,
    '--file', composeFile,
    ...arguments_,
  ], {
    cwd: repositoryDirectory,
    env: composeEnvironment,
    stdio: 'inherit',
  })
}

function assertRuntimeDirectory() {
  const expectedSuffix = path.join('frontend', 'e2e', 'runtime')
  if (!runtimeDirectory.endsWith(expectedSuffix)) {
    throw new Error(`Refusing to modify unexpected E2E runtime path: ${runtimeDirectory}`)
  }
}

function silentWav() {
  const sampleRate = 8_000
  const channels = 1
  const bitsPerSample = 16
  const sampleCount = sampleRate
  const blockAlign = channels * bitsPerSample / 8
  const byteRate = sampleRate * blockAlign
  const dataSize = sampleCount * blockAlign
  const buffer = Buffer.alloc(44 + dataSize)

  buffer.write('RIFF', 0)
  buffer.writeUInt32LE(36 + dataSize, 4)
  buffer.write('WAVE', 8)
  buffer.write('fmt ', 12)
  buffer.writeUInt32LE(16, 16)
  buffer.writeUInt16LE(1, 20)
  buffer.writeUInt16LE(channels, 22)
  buffer.writeUInt32LE(sampleRate, 24)
  buffer.writeUInt32LE(byteRate, 28)
  buffer.writeUInt16LE(blockAlign, 32)
  buffer.writeUInt16LE(bitsPerSample, 34)
  buffer.write('data', 36)
  buffer.writeUInt32LE(dataSize, 40)

  return buffer
}

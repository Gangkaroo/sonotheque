import { removeFixtureLibrary, stopPackagedStack } from './packaged-stack'

export default async function globalTeardown() {
  if (process.env.SONOTHEQUE_E2E_KEEP_STACK === 'true') return

  stopPackagedStack()
  await removeFixtureLibrary()
}

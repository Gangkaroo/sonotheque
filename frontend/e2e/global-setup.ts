import {
  baseURL,
  prepareFixtureLibrary,
  startPackagedStack,
  stopPackagedStack,
  writePackagedLogs,
} from './packaged-stack'
import { waitForApplication } from './packaged-api'

export default async function globalSetup() {
  stopPackagedStack(true)
  await prepareFixtureLibrary()

  try {
    startPackagedStack()
    await waitForApplication(baseURL)
  } catch (error) {
    writePackagedLogs()
    stopPackagedStack(true)
    throw error
  }
}

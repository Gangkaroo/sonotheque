import { onScopeDispose, shallowRef } from 'vue'

interface BackgroundJob {
  id: number
  status: string
}

interface BackgroundJobPollingOptions<T extends BackgroundJob> {
  request: (id: number, signal: AbortSignal) => Promise<T>
  isComplete: (job: T) => boolean
  isTerminal: (job: T) => boolean
  onComplete: (job: T) => void | Promise<void>
  onTerminal?: (job: T) => void | Promise<void>
  onError: (cause: unknown) => void
  interval?: number
}

export function useBackgroundJobPolling<T extends BackgroundJob>(options: BackgroundJobPollingOptions<T>) {
  const job = shallowRef<T | null>(null)
  const interval = options.interval ?? 1000
  let timer: ReturnType<typeof setTimeout> | null = null
  let controller: AbortController | null = null
  let generation = 0

  function clearPending() {
    if (timer) clearTimeout(timer)
    timer = null
    controller?.abort()
    controller = null
  }

  function stop() {
    generation++
    clearPending()
  }

  function reset() {
    stop()
    job.value = null
  }

  function start(initialJob: T) {
    stop()
    job.value = initialJob
    schedule(generation)
  }

  function schedule(requestGeneration: number) {
    timer = setTimeout(() => void poll(requestGeneration), interval)
  }

  async function poll(requestGeneration: number) {
    const currentJob = job.value
    if (!currentJob || requestGeneration !== generation) return

    timer = null
    controller = new AbortController()
    try {
      const result = await options.request(currentJob.id, controller.signal)
      if (requestGeneration !== generation) return

      job.value = result
      if (options.isComplete(result)) {
        await options.onComplete(result)
        return
      }
      if (options.isTerminal(result)) {
        await options.onTerminal?.(result)
        return
      }
      schedule(requestGeneration)
    } catch (cause) {
      if (requestGeneration === generation && !isAbortError(cause)) options.onError(cause)
    } finally {
      if (requestGeneration === generation) controller = null
    }
  }

  onScopeDispose(stop)

  return { job, start, stop, reset }
}

function isAbortError(cause: unknown) {
  return cause instanceof Error && cause.name === 'AbortError'
}

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

import { useBackgroundJobPolling } from '@/composables/useBackgroundJobPolling'

interface TestJob {
  id: number
  status: 'pending' | 'running' | 'completed' | 'failed'
}

const wrappers: ReturnType<typeof mount>[] = []

function setup(options: {
  request: (id: number, signal: AbortSignal) => Promise<TestJob>
  onComplete?: (job: TestJob) => void
  onTerminal?: (job: TestJob) => void
  onError?: (cause: unknown) => void
}) {
  let polling!: ReturnType<typeof useBackgroundJobPolling<TestJob>>
  const wrapper = mount(defineComponent({
    setup() {
      polling = useBackgroundJobPolling<TestJob>({
        request: options.request,
        isComplete: job => job.status === 'completed',
        isTerminal: job => job.status === 'failed',
        onComplete: options.onComplete ?? vi.fn(),
        onTerminal: options.onTerminal,
        onError: options.onError ?? vi.fn(),
      })

      return () => h('div')
    },
  }))
  wrappers.push(wrapper)

  return polling
}

afterEach(() => {
  wrappers.splice(0).forEach(wrapper => wrapper.unmount())
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('background job polling', () => {
  it('updates a running job until it completes', async () => {
    vi.useFakeTimers()
    const onComplete = vi.fn()
    const request = vi.fn()
      .mockResolvedValueOnce({ id: 7, status: 'running' })
      .mockResolvedValueOnce({ id: 7, status: 'completed' })
    const polling = setup({ request, onComplete })

    polling.start({ id: 7, status: 'pending' })
    await vi.advanceTimersByTimeAsync(1000)
    expect(polling.job.value?.status).toBe('running')
    expect(onComplete).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1000)
    expect(polling.job.value?.status).toBe('completed')
    expect(onComplete).toHaveBeenCalledOnce()
    await vi.advanceTimersByTimeAsync(2000)
    expect(request).toHaveBeenCalledTimes(2)
  })

  it('stops after a terminal job and exposes its last state', async () => {
    vi.useFakeTimers()
    const onTerminal = vi.fn()
    const request = vi.fn().mockResolvedValue({ id: 3, status: 'failed' })
    const polling = setup({ request, onTerminal })

    polling.start({ id: 3, status: 'pending' })
    await vi.advanceTimersByTimeAsync(1000)

    expect(polling.job.value?.status).toBe('failed')
    expect(onTerminal).toHaveBeenCalledOnce()
    await vi.advanceTimersByTimeAsync(2000)
    expect(request).toHaveBeenCalledOnce()
  })

  it('aborts an in-flight request and ignores its late result when reset', async () => {
    vi.useFakeTimers()
    let resolveRequest!: (job: TestJob) => void
    let signal!: AbortSignal
    const onComplete = vi.fn()
    const request = vi.fn((_id: number, requestSignal: AbortSignal) => {
      signal = requestSignal
      return new Promise<TestJob>(resolve => { resolveRequest = resolve })
    })
    const polling = setup({ request, onComplete })

    polling.start({ id: 9, status: 'pending' })
    await vi.advanceTimersByTimeAsync(1000)
    polling.reset()

    expect(signal.aborted).toBe(true)
    resolveRequest({ id: 9, status: 'completed' })
    await flushPromises()
    expect(polling.job.value).toBeNull()
    expect(onComplete).not.toHaveBeenCalled()
  })

  it('reports request errors without scheduling another poll', async () => {
    vi.useFakeTimers()
    const failure = new Error('Request failed')
    const onError = vi.fn()
    const request = vi.fn().mockRejectedValue(failure)
    const polling = setup({ request, onError })

    polling.start({ id: 5, status: 'pending' })
    await vi.advanceTimersByTimeAsync(1000)

    expect(onError).toHaveBeenCalledWith(failure)
    await vi.advanceTimersByTimeAsync(2000)
    expect(request).toHaveBeenCalledOnce()
  })
})

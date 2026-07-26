export class PlaybackListenAccumulator {
  private accumulatedMs: number
  private lastPositionSeconds: number | null = null

  constructor(initialMs = 0) {
    this.accumulatedMs = normalizeMilliseconds(initialMs)
  }

  resume(positionSeconds: number) {
    this.lastPositionSeconds = normalizePosition(positionSeconds)
  }

  observe(positionSeconds: number) {
    if (this.lastPositionSeconds === null) return this.accumulatedMs

    const position = normalizePosition(positionSeconds)
    const advancedSeconds = position - this.lastPositionSeconds
    this.lastPositionSeconds = position

    if (advancedSeconds > 0) {
      this.accumulatedMs += advancedSeconds * 1000
    }

    return this.accumulatedMs
  }

  suspend(positionSeconds?: number) {
    if (positionSeconds !== undefined) {
      this.observe(positionSeconds)
    }
    this.lastPositionSeconds = null

    return this.accumulatedMs
  }

  reset(initialMs = 0) {
    this.accumulatedMs = normalizeMilliseconds(initialMs)
    this.lastPositionSeconds = null
  }

  elapsedMs() {
    return Math.round(this.accumulatedMs)
  }
}

function normalizeMilliseconds(value: number) {
  return Number.isFinite(value) && value > 0 ? value : 0
}

function normalizePosition(value: number) {
  return Number.isFinite(value) && value > 0 ? value : 0
}

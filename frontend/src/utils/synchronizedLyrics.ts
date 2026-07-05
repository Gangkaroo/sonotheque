export interface SynchronizedLyricLine {
  timeSeconds: number
  text: string
}

export function parseSynchronizedLyrics(value: string | null | undefined): SynchronizedLyricLine[] {
  if (!value) return []

  let offsetMilliseconds = 0
  const lines: SynchronizedLyricLine[] = []

  for (const rawLine of value.split(/\r?\n/)) {
    const offset = rawLine.trim().match(/^\[offset:([+-]?\d+)\]$/i)
    if (offset) {
      offsetMilliseconds = Number(offset[1])
      continue
    }

    const text = rawLine.replace(/^(?:\[\d{1,3}:\d{2}(?:\.\d{1,3})?\])+\s*/, '').trim()
    if (!text) continue

    for (const timestamp of rawLine.matchAll(/\[(\d{1,3}):(\d{2})(?:\.(\d{1,3}))?\]/g)) {
      const minutes = Number(timestamp[1])
      const seconds = Number(timestamp[2])
      if (seconds >= 60) continue

      const milliseconds = Number((timestamp[3] ?? '').padEnd(3, '0'))
      lines.push({
        timeSeconds: Math.max(0, (minutes * 60) + seconds + (milliseconds / 1000)),
        text,
      })
    }
  }

  return lines
    .map((line) => ({
      ...line,
      timeSeconds: Math.max(0, line.timeSeconds + (offsetMilliseconds / 1000)),
    }))
    .sort((left, right) => left.timeSeconds - right.timeSeconds)
}

export function activeSynchronizedLyricIndex(
  lines: SynchronizedLyricLine[],
  playbackSeconds: number,
): number {
  for (let index = lines.length - 1; index >= 0; index -= 1) {
    const line = lines[index]
    if (line && line.timeSeconds <= playbackSeconds) return index
  }

  return -1
}

import { describe, expect, it } from 'vitest'

import {
  formatApproximateDuration,
  formatDateOnly,
  formatDateTime,
  formatDuration,
  formatTotalDuration,
} from '@/utils/formatters'

describe('formatters', () => {
  it('formats millisecond durations and preserves a caller fallback', () => {
    expect(formatDuration(125000)).toBe('2:05')
    expect(formatDuration(0)).toBe('-')
    expect(formatDuration(undefined, 'n/a')).toBe('n/a')
  })

  it('formats collection durations with an hour component when needed', () => {
    expect(formatTotalDuration(125000)).toBe('2:05')
    expect(formatTotalDuration(3725000)).toBe('1:02:05')
    expect(formatTotalDuration(0, 'n/a')).toBe('n/a')
  })

  it('formats approximate durations with at most two localized units', () => {
    expect(formatApproximateDuration(30_000, 'en')).toBe('1 min')
    expect(formatApproximateDuration(7_500_000, 'en')).toBe('2 hr 5 min')
    expect(formatApproximateDuration(183_600_000, 'en')).toBe('2 days 3 hr')
    expect(formatApproximateDuration(173_100_000, 'en')).toBe('2 days')
  })

  it('formats localized dates and preserves invalid source values', () => {
    expect(formatDateOnly('2026-07-11', 'de-DE')).toContain('2026')
    expect(formatDateTime('not-a-date', 'en-US')).toBe('not-a-date')
    expect(formatDateTime(null, 'en-US', null)).toBeNull()
  })
})

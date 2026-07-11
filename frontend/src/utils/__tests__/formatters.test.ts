import { describe, expect, it } from 'vitest'

import { formatDateOnly, formatDateTime, formatDuration } from '@/utils/formatters'

describe('formatters', () => {
  it('formats millisecond durations and preserves a caller fallback', () => {
    expect(formatDuration(125000)).toBe('2:05')
    expect(formatDuration(0)).toBe('-')
    expect(formatDuration(undefined, 'n/a')).toBe('n/a')
  })

  it('formats localized dates and preserves invalid source values', () => {
    expect(formatDateOnly('2026-07-11', 'de-DE')).toContain('2026')
    expect(formatDateTime('not-a-date', 'en-US')).toBe('not-a-date')
    expect(formatDateTime(null, 'en-US', null)).toBeNull()
  })
})

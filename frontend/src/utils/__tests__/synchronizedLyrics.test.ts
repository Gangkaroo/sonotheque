import { describe, expect, it } from 'vitest'

import {
  activeSynchronizedLyricIndex,
  parseSynchronizedLyrics,
} from '@/utils/synchronizedLyrics'

describe('synchronized lyrics', () => {
  it('parses, offsets, and orders timestamped lines', () => {
    const lines = parseSynchronizedLyrics([
      '[offset:250]',
      '[00:12.50]Second line',
      '[00:01.2][00:05.020]First line',
      '[ar:Example Artist]',
      'Malformed line',
      '[00:15.00]   ',
      '',
      '   ',
    ].join('\n'))

    expect(lines).toEqual([
      { timeSeconds: 1.45, text: 'First line' },
      { timeSeconds: 5.27, text: 'First line' },
      { timeSeconds: 12.75, text: 'Second line' },
    ])
  })

  it('finds the latest line at the playback position', () => {
    const lines = parseSynchronizedLyrics('[00:01.00]One\n[00:03.00]Two')

    expect(activeSynchronizedLyricIndex(lines, 0.5)).toBe(-1)
    expect(activeSynchronizedLyricIndex(lines, 1)).toBe(0)
    expect(activeSynchronizedLyricIndex(lines, 4)).toBe(1)
  })
})

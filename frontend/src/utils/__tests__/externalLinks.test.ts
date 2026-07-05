import { afterEach, describe, expect, it, vi } from 'vitest'

import { openExternalUrl } from '@/utils/externalLinks'

describe('openExternalUrl', () => {
  afterEach(() => vi.restoreAllMocks())

  it('opens HTTP links in a separate tab without an opener', () => {
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)

    openExternalUrl('https://musicbrainz.org/artist/example')

    expect(open).toHaveBeenCalledWith(
      'https://musicbrainz.org/artist/example',
      '_blank',
      'noopener,noreferrer',
    )
  })

  it('ignores malformed and unsafe URLs', () => {
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)

    openExternalUrl('javascript:alert(1)')
    openExternalUrl('not a URL')

    expect(open).not.toHaveBeenCalled()
  })
})

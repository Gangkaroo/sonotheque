import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'

import { useNowPlayingPanelStore } from '@/stores/nowPlayingPanel'

describe('now playing panel store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('opens the requested tab and closes without losing the selection', () => {
    const panel = useNowPlayingPanelStore()

    panel.open('info')

    expect(panel.isOpen).toBe(true)
    expect(panel.activeTab).toBe('info')

    panel.close()

    expect(panel.isOpen).toBe(false)
    expect(panel.activeTab).toBe('info')
  })

  it('toggles the selected tab and switches from another open tab', () => {
    const panel = useNowPlayingPanelStore()

    panel.open('queue')
    panel.toggle('info')

    expect(panel.isOpen).toBe(true)
    expect(panel.activeTab).toBe('info')

    panel.toggle('info')

    expect(panel.isOpen).toBe(false)
  })
})

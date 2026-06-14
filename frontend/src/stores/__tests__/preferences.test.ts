import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'

import { usePreferencesStore } from '@/stores/preferences'

describe('preferences store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('switches locale and theme preferences', () => {
    const preferences = usePreferencesStore()

    preferences.setLocale('de')
    preferences.toggleTheme()

    expect(preferences.locale).toBe('de')
    expect(preferences.theme).toBe('light')
  })
})

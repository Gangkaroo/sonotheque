import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'

import { usePreferencesStore } from '@/stores/preferences'

describe('preferences store', () => {
  beforeEach(() => {
    window.localStorage.clear()
    setActivePinia(createPinia())
  })

  it('switches locale and theme preferences', () => {
    const preferences = usePreferencesStore()

    preferences.setLocale('de')
    preferences.toggleTheme()

    expect(preferences.locale).toBe('de')
    expect(preferences.theme).toBe('light')
  })

  it('loads saved preferences when the store is created again', () => {
    const preferences = usePreferencesStore()

    preferences.setLocale('de')
    preferences.toggleTheme()

    setActivePinia(createPinia())
    const restoredPreferences = usePreferencesStore()

    expect(restoredPreferences.locale).toBe('de')
    expect(restoredPreferences.theme).toBe('light')
  })

  it('falls back to defaults when saved preferences are invalid', () => {
    window.localStorage.setItem('music-library:preferences', JSON.stringify({ locale: 'fr', theme: 'blue' }))

    const preferences = usePreferencesStore()

    expect(preferences.locale).toBe('en')
    expect(preferences.theme).toBe('dark')
  })
})

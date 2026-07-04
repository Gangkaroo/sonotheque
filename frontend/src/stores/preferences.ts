import { defineStore } from 'pinia'

import type { AppLocale } from '@/plugins/i18n'

export type ThemePreference = 'dark' | 'light'

interface StoredPreferences {
  locale?: AppLocale
  theme?: ThemePreference
}

const storageKey = 'music-library:preferences'
const defaultLocale: AppLocale = 'en'
const defaultTheme: ThemePreference = 'dark'
const locales = new Set<AppLocale>(['de', 'en'])
const themes = new Set<ThemePreference>(['dark', 'light'])

function storedPreferences(): StoredPreferences {
  try {
    const stored = window.localStorage.getItem(storageKey)
    if (!stored) return {}

    const parsed = JSON.parse(stored) as Partial<StoredPreferences>

    return {
      locale: typeof parsed.locale === 'string' && locales.has(parsed.locale as AppLocale)
        ? parsed.locale as AppLocale
        : undefined,
      theme: typeof parsed.theme === 'string' && themes.has(parsed.theme as ThemePreference)
        ? parsed.theme as ThemePreference
        : undefined,
    }
  } catch {
    return {}
  }
}

function savePreferences(preferences: Required<StoredPreferences>) {
  window.localStorage.setItem(storageKey, JSON.stringify(preferences))
}

export const usePreferencesStore = defineStore('preferences', {
  state: () => {
    const stored = storedPreferences()

    return {
      locale: stored.locale ?? defaultLocale,
      theme: stored.theme ?? defaultTheme,
    }
  },
  actions: {
    setLocale(locale: AppLocale) {
      this.locale = locale
      this.save()
    },
    setTheme(theme: ThemePreference) {
      this.theme = theme
      this.save()
    },
    toggleTheme() {
      this.setTheme(this.theme === 'dark' ? 'light' : 'dark')
    },
    save() {
      savePreferences({ locale: this.locale, theme: this.theme })
    },
  },
})

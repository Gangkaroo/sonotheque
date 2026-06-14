import { defineStore } from 'pinia'

import type { AppLocale } from '@/plugins/i18n'

export type ThemePreference = 'dark' | 'light'

export const usePreferencesStore = defineStore('preferences', {
  state: () => ({
    locale: 'en' as AppLocale,
    theme: 'dark' as ThemePreference,
  }),
  actions: {
    setLocale(locale: AppLocale) {
      this.locale = locale
    },
    toggleTheme() {
      this.theme = this.theme === 'dark' ? 'light' : 'dark'
    },
  },
})

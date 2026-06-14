import { mdi } from 'vuetify/iconsets/mdi'
import { createVuetify } from 'vuetify'

export const vuetify = createVuetify({
  icons: {
    defaultSet: 'mdi',
    sets: { mdi },
  },
  theme: {
    defaultTheme: 'musicDark',
    themes: {
      musicDark: {
        dark: true,
        colors: {
          background: '#10131a',
          surface: '#181d27',
          'surface-bright': '#252c39',
          primary: '#8fbcff',
          secondary: '#d8b4fe',
          accent: '#66d9c5',
          error: '#ff8d8d',
          info: '#81d4fa',
          success: '#8bd6a6',
          warning: '#ffd180',
        },
      },
      musicLight: {
        dark: false,
        colors: {
          background: '#f4f6fa',
          surface: '#ffffff',
          'surface-bright': '#e9edf4',
          primary: '#345ca8',
          secondary: '#7043a3',
          accent: '#147d70',
          error: '#b3261e',
          info: '#086b91',
          success: '#216e39',
          warning: '#8a5700',
        },
      },
    },
  },
})

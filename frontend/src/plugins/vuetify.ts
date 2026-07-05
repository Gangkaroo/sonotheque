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
          surface: '#191a22',
          'surface-bright': '#26272b',
          primary: '#ffb66b',
          secondary: '#d8b4fe',
          accent: '#fbf975',
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
          primary: '#c65d00',
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

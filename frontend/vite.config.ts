import { fileURLToPath, URL } from 'node:url'

import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'
import vuetify from 'vite-plugin-vuetify'

const allowedHosts = (process.env.SONOTHEQUE_VITE_ALLOWED_HOSTS ?? '')
  .split(',')
  .map((host) => host.trim())
  .filter(Boolean)

export default defineConfig({
  plugins: [
    vue(),
    vuetify({ autoImport: true }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: '127.0.0.1',
    port: 5173,
    allowedHosts,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: false,
        xfwd: true,
      },
    },
  },
})

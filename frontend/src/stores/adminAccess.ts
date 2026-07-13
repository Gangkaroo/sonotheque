import { defineStore } from 'pinia'

const sessionKey = 'sonotheque:admin-token'
const persistentKey = 'sonotheque:admin-token:remembered'

function read(storage: Storage, key: string) {
  try {
    return storage.getItem(key)?.trim() ?? ''
  } catch {
    return ''
  }
}

function remove(storage: Storage, key: string) {
  try {
    storage.removeItem(key)
  } catch {
    // Storage can be unavailable in privacy-restricted browser contexts.
  }
}

function write(storage: Storage, key: string, value: string) {
  try {
    storage.setItem(key, value)
  } catch {
    // The in-memory store still works for the current page.
  }
}

export function adminToken() {
  return read(window.sessionStorage, sessionKey) || read(window.localStorage, persistentKey)
}

export const useAdminAccessStore = defineStore('adminAccess', {
  state: () => {
    const persistentToken = read(window.localStorage, persistentKey)

    return {
      token: read(window.sessionStorage, sessionKey) || persistentToken,
      remember: persistentToken !== '',
      revision: 0,
    }
  },
  getters: {
    hasToken: (state) => state.token !== '',
  },
  actions: {
    save(token: string, remember: boolean) {
      const normalized = token.trim()
      remove(window.sessionStorage, sessionKey)
      remove(window.localStorage, persistentKey)
      if (normalized) {
        write(remember ? window.localStorage : window.sessionStorage, remember ? persistentKey : sessionKey, normalized)
      }
      this.token = normalized
      this.remember = remember && normalized !== ''
      this.revision += 1
    },
    clear() {
      this.save('', false)
    },
  },
})

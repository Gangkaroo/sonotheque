import { defineStore } from 'pinia'

export type NowPlayingPanelTab = 'info' | 'lyrics' | 'queue'

export const useNowPlayingPanelStore = defineStore('nowPlayingPanel', {
  state: () => ({
    activeTab: 'queue' as NowPlayingPanelTab,
    isOpen: false,
  }),
  actions: {
    open(tab: NowPlayingPanelTab) {
      this.activeTab = tab
      this.isOpen = true
    },
    close() {
      this.isOpen = false
    },
    toggle(tab: NowPlayingPanelTab) {
      if (this.isOpen && this.activeTab === tab) {
        this.close()
        return
      }

      this.open(tab)
    },
  },
})

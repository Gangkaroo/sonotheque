import { createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

import OwnedAlbumCopies from '@/components/OwnedAlbumCopies.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'

describe('OwnedAlbumCopies', () => {
  beforeAll(() => {
    vi.stubGlobal('ResizeObserver', class {
      observe() {}
      unobserve() {}
      disconnect() {}
    })
    Object.defineProperty(window, 'visualViewport', {
      configurable: true,
      value: {
        width: 1024,
        height: 768,
        offsetLeft: 0,
        offsetTop: 0,
        pageLeft: 0,
        pageTop: 0,
        scale: 1,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
      },
    })
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('keeps copies distinct and opens the add-copy form', async () => {
    i18n.global.locale.value = 'en'
    const wrapper = mount(OwnedAlbumCopies, {
      attachTo: document.body,
      props: {
        albumId: 5,
        albumTitle: 'Album',
        artistName: 'Artist',
        releaseYear: 2001,
        copies: [
          {
            id: 9,
            isPhysical: true,
            physicalFormat: 'vinyl',
            purchaseSource: 'Record store',
            purchasePriceAmount: '29.95',
            purchasePriceCurrency: 'EUR',
            provider: null,
          },
          {
            id: 10,
            isPhysical: true,
            physicalFormat: 'cd',
            purchaseSource: 'Online shop',
            provider: 'discogs',
            externalReleaseId: 456,
          },
        ],
      },
      global: {
        plugins: [createPinia(), i18n, vuetify],
      },
    })

    expect(wrapper.text()).toContain('Vinyl · Copy 1')
    expect(wrapper.text()).toContain('CD · Copy 2')
    expect(wrapper.text()).toContain('Record store')
    expect(wrapper.text()).toContain('Match Discogs release')

    await wrapper.get('button').trigger('click')
    await nextTick()

    expect(document.body.textContent).toContain('Add copy')
    expect(document.body.textContent).toContain('Purchase price')
  })
})

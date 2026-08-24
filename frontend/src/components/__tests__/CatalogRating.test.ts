import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import CatalogRating from '@/components/CatalogRating.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'

describe('CatalogRating', () => {
  beforeEach(() => {
    const pinia = createPinia()
    setActivePinia(pinia)
    vi.unstubAllGlobals()
    i18n.global.locale.value = 'en'
  })

  it('saves half-star values and emits the persisted rating', async () => {
    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
      expect(url).toBe('/api/tracks/7/rating')
      expect(init?.method).toBe('PATCH')
      expect(init?.body).toBe(JSON.stringify({ rating: 4.5 }))

      return new Response(JSON.stringify({ id: 7, rating: 4.5 }), { status: 200 })
    })
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mount(CatalogRating, {
      props: {
        entityId: 7,
        entityType: 'track',
        modelValue: 3,
      },
      global: { plugins: [createPinia(), i18n, vuetify] },
    })

    wrapper.getComponent({ name: 'VRating' }).vm.$emit('update:modelValue', 4.5)
    await flushPromises()

    expect(wrapper.emitted('update:modelValue')).toEqual([[4.5]])
  })

  it('clears an existing rating', async () => {
    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
      expect(url).toBe('/api/albums/4/rating')
      expect(init?.method).toBe('DELETE')

      return new Response(null, { status: 204 })
    })
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mount(CatalogRating, {
      props: {
        entityId: 4,
        entityType: 'album',
        modelValue: 2.5,
      },
      global: { plugins: [createPinia(), i18n, vuetify] },
    })

    const rating = wrapper.getComponent({ name: 'VRating' })
    Object.defineProperty(rating.element, 'getBoundingClientRect', {
      value: () => ({
        bottom: 20,
        height: 20,
        left: 0,
        right: 100,
        top: 0,
        width: 100,
        x: 0,
        y: 0,
        toJSON: () => ({}),
      }),
    })
    await rating.trigger('click', { clientX: 2 })
    await flushPromises()

    expect(wrapper.emitted('update:modelValue')).toEqual([[null]])
  })
})

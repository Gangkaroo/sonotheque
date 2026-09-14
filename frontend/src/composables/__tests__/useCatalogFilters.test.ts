import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, KeepAlive, ref } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'

import { queryNumber, queryPage, querySearch, useCatalogFilters } from '@/composables/useCatalogFilters'

const cleanups: (() => void)[] = []

async function setup(path = '/albums') {
  const scope = ref<number | null>(null)
  const load = vi.fn()
  const parse = (input: Record<string, unknown>) => ({
    page: queryPage(input.page), search: querySearch(input.search),
    genre: queryNumber(input.genre), year: queryNumber(input.year),
  })
  let state!: ReturnType<typeof useCatalogFilters<ReturnType<typeof parse>>>
  const List = defineComponent({
    setup() {
      state = useCatalogFilters({
        routeName: 'albums', storageKey: 'test:filters', scope: () => scope.value, parse,
        load: filters => load({ ...filters }),
      })
      return () => h('div')
    },
  })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/albums', name: 'albums', component: List },
      { path: '/other', name: 'other', component: { render: () => h('div') } },
    ],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(defineComponent({
    render: () => h(RouterView, {}, {
      default: ({ Component }: { Component: ReturnType<typeof h> }) => h(KeepAlive, {}, () => Component),
    }),
  }), { global: { plugins: [router] } })
  cleanups.push(() => wrapper.unmount())
  await flushPromises()
  return { state, router, scope, load }
}

beforeEach(() => { sessionStorage.clear() })
afterEach(() => {
  cleanups.splice(0).forEach(cleanup => cleanup())
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('catalog filters', () => {
  it('restores filters and page together, including browser back navigation', async () => {
    const { state, router, load } = await setup('/albums?genre=2&page=5')
    expect(state.filters.page).toBe(5)
    await router.push('/albums?genre=7&year=1990&page=4')
    await flushPromises()
    expect(state.filters).toEqual({ genre: 7, year: 1990, page: 4, search: '' })
    expect(load).toHaveBeenLastCalledWith({ genre: 7, year: 1990, page: 4, search: '' })
    router.back()
    await flushPromises()
    expect(state.filters).toEqual({ genre: 2, year: null, page: 5, search: '' })
  })

  it('preserves filters and page across root changes without loading hidden views', async () => {
    const { state, router, scope, load } = await setup('/albums?genre=2&page=5')
    scope.value = 9
    await flushPromises()
    expect(state.filters.page).toBe(5)
    expect(load).toHaveBeenCalledTimes(2)
    await router.push('/other')
    scope.value = 10
    await flushPromises()
    expect(load).toHaveBeenCalledTimes(2)
    await router.push('/albums')
    await flushPromises()
    expect(load).toHaveBeenCalledTimes(3)
    expect(state.filters.page).toBe(5)
    expect(router.currentRoute.value.query).toEqual({ genre: '2', page: '5' })
  })

  it('uses explicit incoming filters instead of combining them with remembered filters', async () => {
    sessionStorage.setItem('test:filters', JSON.stringify({ page: 8, search: 'Metallica', year: 1986 }))
    const { state } = await setup('/albums?genre=7')
    expect(state.filters).toEqual({ page: 1, search: '', year: null, genre: 7 })
  })

  it('debounces typing and preserves trailing spaces through route synchronization', async () => {
    const { state, load, router } = await setup('/albums?page=5')
    vi.useFakeTimers()
    state.searchInput.value = 'Virgin '
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    expect(state.searchInput.value).toBe('Virgin ')
    expect(router.currentRoute.value.query.search).toBe('Virgin')
    state.searchInput.value += 'Steele'
    await vi.advanceTimersByTimeAsync(299)
    expect(load).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(1)
    await flushPromises()
    expect(load).toHaveBeenLastCalledWith({ page: 1, search: 'Virgin Steele', year: null, genre: null })
    state.clearSearch()
    await flushPromises()
    expect(state.filters.search).toBe('')
  })

  it('cancels a pending search when navigating away', async () => {
    const { state, router, load } = await setup()
    vi.useFakeTimers()
    state.searchInput.value = 'Unsubmitted'
    await router.push('/other')
    await vi.advanceTimersByTimeAsync(400)
    expect(load).toHaveBeenCalledOnce()
  })

  it('resets page for a user filter change and handles unavailable browser storage', async () => {
    const { state, router } = await setup('/albums?page=5')
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('Unavailable') })
    state.filters.genre = 3
    await flushPromises()
    expect(state.filters.page).toBe(1)
    expect(router.currentRoute.value.query).toEqual({ genre: '3' })
  })
})

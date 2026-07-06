import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'

import { useLibraryRootScopeStore, withLibraryRootScope } from '@/stores/libraryRootScope'

describe('library root scope store', () => {
  beforeEach(() => {
    window.sessionStorage.clear()
    setActivePinia(createPinia())
    useLibraryRootScopeStore().select(null)
  })

  it('persists the selected root for the browser session and scopes request paths', () => {
    const scope = useLibraryRootScopeStore()

    scope.select(12)

    expect(scope.selectedRootId).toBe(12)
    expect(scope.scopeKey).toBe('root-12')
    expect(window.sessionStorage.getItem('music-library.active-library-root')).toBe('12')
    expect(withLibraryRootScope('/catalog/albums?page=2')).toBe('/catalog/albums?page=2&libraryRoot=12')
  })

  it('returns to all roots when the stored root is unavailable or disabled', () => {
    const scope = useLibraryRootScopeStore()
    scope.select(12)

    scope.ensureValid([{
      id: 12,
      name: 'Offline disk',
      path: 'D:/Music',
      coverImagePaths: [],
      excludedDirectories: [],
      enabled: false,
      lastScannedAt: null,
    }])

    expect(scope.selectedRootId).toBeNull()
    expect(scope.scopeKey).toBe('all')
    expect(window.sessionStorage.getItem('music-library.active-library-root')).toBeNull()
    expect(withLibraryRootScope('/catalog/albums')).toBe('/catalog/albums')
  })
})

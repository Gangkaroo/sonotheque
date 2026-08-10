import { createRouter, createWebHistory } from 'vue-router'

const scrollPositions = new Map<string, { left: number, top: number }>()
const MAX_SCROLL_POSITIONS = 30

export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/setup', name: 'setup', component: () => import('@/views/SetupView.vue') },
    { path: '/', name: 'dashboard', component: () => import('@/views/DashboardView.vue'), meta: { keepAlive: true } },
    { path: '/artists', name: 'artists', component: () => import('@/views/ArtistsView.vue'), meta: { keepAlive: true } },
    { path: '/artists/:id', name: 'artist-detail', component: () => import('@/views/ArtistDetailView.vue') },
    { path: '/musicians', name: 'musicians', component: () => import('@/views/MusiciansView.vue'), meta: { keepAlive: true } },
    { path: '/musicians/:id', name: 'musician-detail', component: () => import('@/views/MusicianDetailView.vue') },
    { path: '/albums', name: 'albums', component: () => import('@/views/AlbumsView.vue'), meta: { keepAlive: true } },
    { path: '/albums/:id', name: 'album-detail', component: () => import('@/views/AlbumDetailView.vue') },
    { path: '/genres', name: 'genres', component: () => import('@/views/GenresView.vue'), meta: { keepAlive: true } },
    { path: '/tracks', name: 'tracks', component: () => import('@/views/TracksView.vue'), meta: { keepAlive: true } },
    { path: '/tracks/:id', name: 'track-detail', component: () => import('@/views/TrackDetailView.vue') },
    { path: '/folders', name: 'folders', component: () => import('@/views/FoldersView.vue'), meta: { keepAlive: true } },
    { path: '/history', name: 'history', component: () => import('@/views/HistoryView.vue'), meta: { keepAlive: true } },
    { path: '/trash', name: 'trash', component: () => import('@/views/TrashView.vue'), meta: { keepAlive: true } },
    { path: '/playlists', name: 'playlists', component: () => import('@/views/PlaylistsView.vue'), meta: { keepAlive: true } },
    { path: '/playlists/:id', name: 'playlist-detail', component: () => import('@/views/PlaylistDetailView.vue') },
    { path: '/favorites', name: 'favorites', component: () => import('@/views/FavoritesView.vue'), meta: { keepAlive: true } },
    { path: '/settings', name: 'settings', component: () => import('@/views/SettingsView.vue') },
  ],
  scrollBehavior: (to, _from, savedPosition) => savedPosition
    ?? (to.meta.keepAlive ? scrollPositions.get(to.fullPath) : undefined)
    ?? { top: 0 },
})

router.beforeEach((_to, from) => {
  if (!from.meta.keepAlive || typeof window === 'undefined') return

  scrollPositions.delete(from.fullPath)
  scrollPositions.set(from.fullPath, { left: window.scrollX, top: window.scrollY })
  if (scrollPositions.size > MAX_SCROLL_POSITIONS) {
    const oldestKey = scrollPositions.keys().next().value
    if (oldestKey) scrollPositions.delete(oldestKey)
  }
})

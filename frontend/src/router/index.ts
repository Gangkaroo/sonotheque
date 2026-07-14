import { createRouter, createWebHistory } from 'vue-router'

export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/setup', name: 'setup', component: () => import('@/views/SetupView.vue') },
    { path: '/', name: 'dashboard', component: () => import('@/views/DashboardView.vue') },
    { path: '/artists', name: 'artists', component: () => import('@/views/ArtistsView.vue') },
    { path: '/artists/:id', name: 'artist-detail', component: () => import('@/views/ArtistDetailView.vue') },
    { path: '/albums', name: 'albums', component: () => import('@/views/AlbumsView.vue') },
    { path: '/albums/:id', name: 'album-detail', component: () => import('@/views/AlbumDetailView.vue') },
    { path: '/genres', name: 'genres', component: () => import('@/views/GenresView.vue') },
    { path: '/tracks', name: 'tracks', component: () => import('@/views/TracksView.vue') },
    { path: '/tracks/:id', name: 'track-detail', component: () => import('@/views/TrackDetailView.vue') },
    { path: '/folders', name: 'folders', component: () => import('@/views/FoldersView.vue') },
    { path: '/history', name: 'history', component: () => import('@/views/HistoryView.vue') },
    { path: '/playlists', name: 'playlists', component: () => import('@/views/PlaylistsView.vue') },
    { path: '/playlists/:id', name: 'playlist-detail', component: () => import('@/views/PlaylistDetailView.vue') },
    { path: '/favorites', name: 'favorites', component: () => import('@/views/FavoritesView.vue') },
    { path: '/settings', name: 'settings', component: () => import('@/views/SettingsView.vue') },
  ],
  scrollBehavior: () => ({ top: 0 }),
})

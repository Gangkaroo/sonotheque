<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTheme } from 'vuetify'

import AppPlayer from '@/components/AppPlayer.vue'
import { useFavoritesStore } from '@/stores/favorites'
import { usePreferencesStore } from '@/stores/preferences'
import { usePlayerStore } from '@/stores/player'

const drawer = ref(/** @type {boolean | null} */ (null))
const favorites = useFavoritesStore()
const preferences = usePreferencesStore()
const player = usePlayerStore()
const { locale, t } = useI18n()
const theme = useTheme()

const navigation = computed(() => [
  { title: t('navigation.dashboard'), icon: 'mdi-view-dashboard-outline', to: '/' },
  { title: t('navigation.artists'), icon: 'mdi-account-music-outline', to: '/artists' },
  { title: t('navigation.genres'), icon: 'mdi-tag-multiple-outline', to: '/genres' },
  { title: t('navigation.albums'), icon: 'mdi-album', to: '/albums' },
  { title: t('navigation.tracks'), icon: 'mdi-music-note-outline', to: '/tracks' },
  { title: t('navigation.playlists'), icon: 'mdi-playlist-music-outline', to: '/playlists' },
  { title: t('navigation.favorites'), icon: 'mdi-heart-outline', to: '/favorites' },
  { title: t('navigation.history'), icon: 'mdi-history', to: '/history' },
])
const nowPlayingRoute = computed(() => {
  const track = player.currentTrack
  if (!track) return null

  const route = { name: 'track-detail', params: { id: track.id } }
  if (player.playbackContext === 'album' && track.album?.id) {
    return { ...route, query: { backAlbum: track.album.id } }
  }

  return route
})

watch(
  () => preferences.locale,
  (value) => {
    locale.value = value
    document.documentElement.lang = value
  },
  { immediate: true },
)

onMounted(() => {
  void favorites.loadIds()
})

watch(
  () => preferences.theme,
  (value) => {
    theme.change(value === 'dark' ? 'musicDark' : 'musicLight')
  },
  { immediate: true },
)

/** @param {unknown} value */
function setLocale(value) {
  if (value === 'de' || value === 'en') {
    preferences.setLocale(value)
  }
}
</script>

<template>
  <v-app>
    <v-navigation-drawer v-model="drawer" width="272">
      <div class="brand pa-5">
        <v-icon color="primary" icon="mdi-waveform" size="34" />
        <div>
          <div class="text-h6 font-weight-bold">{{ t('app.name') }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('app.subtitle') }}</div>
        </div>
      </div>

      <v-divider />

      <v-list nav class="pa-3">
        <v-list-item
          v-for="item in navigation"
          :key="item.to"
          :prepend-icon="item.icon"
          :title="item.title"
          :to="item.to"
          rounded="lg"
        />
      </v-list>

      <template v-if="nowPlayingRoute">
        <v-divider />
        <v-list nav class="pa-3">
          <v-list-item
            color="primary"
            prepend-icon="mdi-play-circle-outline"
            :subtitle="player.currentTrack?.title"
            :title="t('navigation.nowPlaying')"
            :to="nowPlayingRoute"
            rounded="lg"
          />
        </v-list>
      </template>

      <template #append>
        <v-list nav class="pa-3">
          <v-list-item
            prepend-icon="mdi-cog-outline"
            :title="t('navigation.settings')"
            to="/settings"
            rounded="lg"
          />
        </v-list>
      </template>
    </v-navigation-drawer>

    <v-app-bar flat border="b">
      <v-app-bar-nav-icon :aria-label="t('actions.toggleNavigation')" @click="drawer = !drawer" />
      <v-app-bar-title class="app-title">{{ t('app.name') }}</v-app-bar-title>

      <template #append>
        <v-btn
          v-if="nowPlayingRoute"
          color="primary"
          :aria-label="t('navigation.nowPlaying')"
          icon="mdi-play-circle-outline"
          :to="nowPlayingRoute"
          variant="text"
        />
        <v-btn
          :aria-label="t('actions.toggleTheme')"
          :icon="preferences.theme === 'dark' ? 'mdi-weather-sunny' : 'mdi-weather-night'"
          @click="preferences.toggleTheme"
        />
        <v-select
          class="locale-select mr-4"
          density="compact"
          hide-details
          :items="[
            { title: 'English', value: 'en' },
            { title: 'Deutsch', value: 'de' },
          ]"
          :label="t('settings.language')"
          :model-value="preferences.locale"
          variant="outlined"
          @update:model-value="setLocale"
        />
      </template>
    </v-app-bar>

    <v-main>
      <v-container class="page-container py-8" fluid>
        <router-view />
      </v-container>
    </v-main>

    <AppPlayer />
  </v-app>
</template>

<style scoped>
.brand {
  display: flex;
  align-items: center;
  gap: 14px;
}

.locale-select {
  width: 142px;
}

.page-container {
  max-width: 1600px;
}

@media (max-width: 500px) {
  .app-title {
    display: none;
  }
}
</style>

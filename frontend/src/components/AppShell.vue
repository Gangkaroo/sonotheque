<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTheme } from 'vuetify'

import AppPlayer from '@/components/AppPlayer.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useFavoritesStore } from '@/stores/favorites'
import { useNowPlayingPanelStore } from '@/stores/nowPlayingPanel'
import { usePreferencesStore } from '@/stores/preferences'
import { usePlayerStore } from '@/stores/player'

const drawer = ref(/** @type {boolean | null} */ (null))
const favorites = useFavoritesStore()
const nowPlayingPanel = useNowPlayingPanelStore()
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

      <template v-if="player.currentTrack">
        <v-divider />
        <v-list nav class="pa-3">
          <v-list-item
            color="primary"
            link
            prepend-icon="mdi-play-circle-outline"
            :subtitle="player.currentTrack?.title"
            :title="t('navigation.playbackDetails')"
            rounded="lg"
            @click="nowPlayingPanel.open('info')"
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
      <v-tooltip :text="t('actions.toggleNavigation')" location="bottom">
        <template #activator="{ props }">
          <v-app-bar-nav-icon v-bind="props" :aria-label="t('actions.toggleNavigation')" @click="drawer = !drawer" />
        </template>
      </v-tooltip>
      <v-app-bar-title class="app-title">{{ t('app.name') }}</v-app-bar-title>

      <template #append>
        <TooltipIconButton
          v-if="player.currentTrack"
          :text="t('navigation.playbackDetails')"
          color="primary"
          :aria-label="t('navigation.playbackDetails')"
          icon="mdi-information-outline"
          location="bottom"
          variant="text"
          @click="nowPlayingPanel.toggle('info')"
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

.page-container {
  max-width: 1600px;
}

@media (max-width: 500px) {
  .app-title {
    display: none;
  }
}
</style>

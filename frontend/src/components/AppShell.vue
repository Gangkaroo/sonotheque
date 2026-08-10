<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useTheme } from 'vuetify'

import AppPlayer from '@/components/AppPlayer.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useCatalogStore } from '@/stores/catalog'
import { useFavoritesStore } from '@/stores/favorites'
import { useFirstRunSetupStore } from '@/stores/firstRunSetup'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'
import { useLibraryRootsStore } from '@/stores/libraryRoots'
import { useNowPlayingPanelStore } from '@/stores/nowPlayingPanel'
import { usePreferencesStore } from '@/stores/preferences'
import { usePlayerStore } from '@/stores/player'

const drawer = ref(/** @type {boolean | null} */ (null))
const catalog = useCatalogStore()
const favorites = useFavoritesStore()
const firstRunSetup = useFirstRunSetupStore()
const libraryRootScope = useLibraryRootScopeStore()
const libraryRoots = useLibraryRootsStore()
const nowPlayingPanel = useNowPlayingPanelStore()
const preferences = usePreferencesStore()
const player = usePlayerStore()
const route = useRoute()
const router = useRouter()
const isSetup = computed(() => route.name === 'setup')
const { locale, t } = useI18n()
const theme = useTheme()
const canNavigateBack = computed(() => {
  void route.fullPath

  return typeof window.history.state?.back === 'string'
})
const canNavigateForward = computed(() => {
  void route.fullPath

  return typeof window.history.state?.forward === 'string'
})
const filterableListRoutes = new Set(['artists', 'musicians', 'albums', 'tracks', 'genres'])
const viewKey = computed(() => {
  const routeName = String(route.name ?? 'unknown')

  return filterableListRoutes.has(routeName)
    ? routeName
    : `${routeName}:${libraryRootScope.scopeKey}`
})
const libraryRootOptions = computed(() => [
  { title: t('libraryScope.allRoots'), value: null },
  ...libraryRoots.roots
    .filter((root) => root.enabled)
    .map((root) => ({ title: root.name, value: root.id })),
])

const navigationGroups = computed(() => [
  [
    { title: t('navigation.dashboard'), icon: 'mdi-view-dashboard-outline', to: '/' },
  ],
  [
    { title: t('navigation.albums'), icon: 'mdi-album', to: '/albums' },
    { title: t('navigation.tracks'), icon: 'mdi-music-note-outline', to: '/tracks' },
    { title: t('navigation.playlists'), icon: 'mdi-playlist-music-outline', to: '/playlists' },
    { title: t('navigation.favorites'), icon: 'mdi-heart-outline', to: '/favorites' },
  ],
  [
    { title: t('navigation.genres'), icon: 'mdi-tag-multiple-outline', to: '/genres' },
    { title: t('navigation.artists'), icon: 'mdi-account-music-outline', to: '/artists' },
    { title: t('navigation.musicians'), icon: 'mdi-account-star-outline', to: '/musicians' },
  ],
  [
    { title: t('navigation.folders'), icon: 'mdi-folder-music-outline', to: '/folders' },
    { title: t('navigation.history'), icon: 'mdi-history', to: '/history' },
    { title: t('navigation.trash'), icon: 'mdi-delete-clock-outline', to: '/trash' },
  ],
])
watch(
  () => preferences.locale,
  (value) => {
    locale.value = value
    document.documentElement.lang = value
  },
  { immediate: true },
)

onMounted(async () => {
  await Promise.all([libraryRoots.load(), favorites.loadIds(), firstRunSetup.load()])
  libraryRootScope.ensureValid(libraryRoots.roots)
  enforceFirstRunSetup()
})

watch(() => route.name, enforceFirstRunSetup)

function enforceFirstRunSetup() {
  if (firstRunSetup.status && !firstRunSetup.status.completed && route.name !== 'setup') {
    void router.replace({ name: 'setup' })
  }
}

function navigateBack() {
  if (canNavigateBack.value) router.back()
}

function navigateForward() {
  if (canNavigateForward.value) router.forward()
}

watch(
  () => libraryRootScope.selectedRootId,
  () => {
    catalog.invalidateMetrics()
    void favorites.loadIds(true)
  },
  { flush: 'sync' },
)

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
    <v-navigation-drawer v-if="!isSetup" v-model="drawer" width="272">
      <div class="brand pa-5">
        <v-icon color="primary" icon="mdi-waveform" size="34" />
        <div>
          <div class="text-h6 font-weight-bold">{{ t('app.name') }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('app.subtitle') }}</div>
        </div>
      </div>

      <v-divider />

      <template v-for="(group, groupIndex) in navigationGroups" :key="groupIndex">
        <v-divider v-if="groupIndex > 0" />
        <v-list nav class="px-3 py-1">
          <v-list-item
            v-for="item in group"
            :key="item.to"
            :prepend-icon="item.icon"
            :title="item.title"
            :to="item.to"
            rounded="lg"
          />
        </v-list>
      </template>

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

    <v-app-bar v-if="!isSetup" flat border="b">
      <v-tooltip :text="t('actions.toggleNavigation')" location="bottom">
        <template #activator="{ props }">
          <v-app-bar-nav-icon v-bind="props" :aria-label="t('actions.toggleNavigation')" @click="drawer = !drawer" />
        </template>
      </v-tooltip>
      <div class="history-navigation">
        <TooltipIconButton
          :text="t('actions.navigateBack')"
          :aria-label="t('actions.navigateBack')"
          :disabled="!canNavigateBack"
          density="compact"
          icon="mdi-arrow-left"
          location="bottom"
          variant="text"
          @click="navigateBack"
        />
        <TooltipIconButton
          :text="t('actions.navigateForward')"
          :aria-label="t('actions.navigateForward')"
          :disabled="!canNavigateForward"
          density="compact"
          icon="mdi-arrow-right"
          location="bottom"
          variant="text"
          @click="navigateForward"
        />
      </div>
      <v-app-bar-title class="app-title">{{ t('app.name') }}</v-app-bar-title>

      <template #append>
        <v-select
          class="library-root-selector mr-2"
          density="compact"
          hide-details
          item-title="title"
          item-value="value"
          :items="libraryRootOptions"
          :label="t('libraryScope.label')"
          :model-value="libraryRootScope.selectedRootId"
          prepend-inner-icon="mdi-harddisk"
          variant="outlined"
          @update:model-value="libraryRootScope.select($event)"
        />
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
        <router-view v-slot="{ Component, route: currentRoute }">
          <KeepAlive :max="12">
            <component
              :is="Component"
              v-if="currentRoute.meta.keepAlive"
              :key="viewKey"
            />
          </KeepAlive>
          <component
            :is="Component"
            v-if="!currentRoute.meta.keepAlive"
            :key="viewKey"
          />
        </router-view>
      </v-container>
    </v-main>

    <AppPlayer v-if="!isSetup" />
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

.library-root-selector {
  width: clamp(180px, 22vw, 300px);
}

.history-navigation {
  display: flex;
  flex: 0 0 auto;
}

@media (max-width: 500px) {
  .app-title {
    display: none;
  }

  .library-root-selector {
    width: min(52vw, 220px);
  }
}
</style>

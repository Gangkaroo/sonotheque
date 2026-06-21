<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTheme } from 'vuetify'

import AppPlayer from '@/components/AppPlayer.vue'
import { usePreferencesStore } from '@/stores/preferences'

const drawer = ref(/** @type {boolean | null} */ (null))
const preferences = usePreferencesStore()
const { locale, t } = useI18n()
const theme = useTheme()

const navigation = computed(() => [
  { title: t('navigation.dashboard'), icon: 'mdi-view-dashboard-outline', to: '/' },
  { title: t('navigation.artists'), icon: 'mdi-account-music-outline', to: '/artists' },
  { title: t('navigation.albums'), icon: 'mdi-album', to: '/albums' },
  { title: t('navigation.genres'), icon: 'mdi-tag-multiple-outline', to: '/genres' },
  { title: t('navigation.tracks'), icon: 'mdi-music-note-outline', to: '/tracks' },
])

watch(
  () => preferences.locale,
  (value) => {
    locale.value = value
    document.documentElement.lang = value
  },
  { immediate: true },
)

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

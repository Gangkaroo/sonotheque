<script setup>
import { useI18n } from 'vue-i18n'

import { usePreferencesStore } from '@/stores/preferences'

const { t } = useI18n()
const preferences = usePreferencesStore()

/** @param {unknown} value */
function setLocale(value) {
  if (value === 'de' || value === 'en') preferences.setLocale(value)
}

/** @param {boolean | null} enabled */
function setDarkMode(enabled) {
  preferences.setTheme(enabled ? 'dark' : 'light')
}
</script>

<template>
  <v-card border class="general-settings-card mt-6" rounded="xl">
    <v-card-item class="pa-6 pb-2" prepend-icon="mdi-tune-variant">
      <v-card-title>{{ t('settings.general') }}</v-card-title>
      <v-card-subtitle>{{ t('settings.generalDescription') }}</v-card-subtitle>
    </v-card-item>

    <v-card-text class="pa-6 pt-4">
      <v-row>
        <v-col cols="12" md="6">
          <v-select
            density="comfortable"
            hide-details="auto"
            :hint="t('settings.languageHint')"
            :items="[
              { title: 'English', value: 'en' },
              { title: 'Deutsch', value: 'de' },
            ]"
            :label="t('settings.language')"
            :model-value="preferences.locale"
            persistent-hint
            prepend-inner-icon="mdi-translate"
            variant="outlined"
            @update:model-value="setLocale"
          />
        </v-col>

        <v-col cols="12" md="6">
          <v-switch
            color="primary"
            density="comfortable"
            hide-details="auto"
            :hint="t('settings.darkModeHint')"
            inset
            :label="t('settings.darkMode')"
            :model-value="preferences.theme === 'dark'"
            persistent-hint
            @update:model-value="setDarkMode"
          />
        </v-col>
      </v-row>
    </v-card-text>
  </v-card>
</template>

<style scoped>
.general-settings-card :deep(.v-card-subtitle) {
  overflow: visible;
  text-overflow: clip;
  white-space: normal;
}
</style>

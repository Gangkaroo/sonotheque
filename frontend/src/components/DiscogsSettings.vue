<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { useDiscogsSettingsStore } from '@/stores/discogsSettings'
import { openExternalUrl } from '@/utils/externalLinks'

const { t } = useI18n()
const discogs = useDiscogsSettingsStore()
const personalAccessToken = ref('')

onMounted(() => discogs.load())

async function connect() {
  try {
    await discogs.connect(personalAccessToken.value.trim())
    personalAccessToken.value = ''
  } catch {
    // The store exposes the API error in the settings card.
  }
}

async function disconnect() {
  try {
    await discogs.disconnect()
  } catch {
    // The store exposes the API error in the settings card.
  }
}
</script>

<template>
  <v-card border rounded="xl" class="mt-6">
    <v-card-item class="pa-6 pb-2" prepend-icon="mdi-album">
      <v-card-title>{{ t('settings.discogs') }}</v-card-title>
      <v-card-subtitle>{{ t('settings.discogsDescription') }}</v-card-subtitle>
    </v-card-item>

    <v-card-text class="pa-6 pt-4">
      <v-alert v-if="discogs.error" class="mb-4" type="error" variant="tonal">
        {{ discogs.error }}
      </v-alert>

      <v-skeleton-loader v-if="discogs.loading" type="list-item-two-line" />

      <template v-else-if="discogs.settings.connected">
        <div class="d-flex flex-wrap align-center ga-3">
          <v-chip color="success" prepend-icon="mdi-check-circle-outline" variant="tonal">
            {{ t('settings.discogsConnectedAs', { username: discogs.settings.username }) }}
          </v-chip>
          <v-btn
            color="error"
            :loading="discogs.saving"
            size="small"
            variant="text"
            @click="disconnect"
          >
            {{ t('settings.discogsDisconnect') }}
          </v-btn>
        </div>
      </template>

      <template v-else>
        <v-alert class="mb-5" type="info" variant="tonal">
          {{ t('settings.discogsTokenHint') }}
          <a
            href="https://www.discogs.com/settings/developers"
            rel="noopener noreferrer"
            target="_blank"
            @click.prevent="openExternalUrl('https://www.discogs.com/settings/developers')"
          >
            {{ t('settings.discogsCreateToken') }}
          </a>
        </v-alert>

        <v-text-field
          v-model="personalAccessToken"
          autocomplete="new-password"
          :disabled="discogs.saving"
          :hint="t('settings.discogsTokenPrivacy')"
          :label="t('settings.discogsPersonalAccessToken')"
          persistent-hint
          type="password"
        />
        <v-btn
          color="primary"
          :disabled="personalAccessToken.trim().length === 0"
          :loading="discogs.saving"
          prepend-icon="mdi-link-variant"
          variant="flat"
          @click="connect"
        >
          {{ t('settings.discogsConnect') }}
        </v-btn>
      </template>
    </v-card-text>
  </v-card>
</template>

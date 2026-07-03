<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { useLastFmSettingsStore } from '@/stores/lastFmSettings'

const { t } = useI18n()
const lastFm = useLastFmSettingsStore()
const apiKey = ref('')
const apiSecret = ref('')

onMounted(() => lastFm.load())

watch(
  () => lastFm.settings.apiKey,
  (value) => {
    if (value && !apiKey.value) apiKey.value = value
  },
  { immediate: true },
)

async function connect() {
  try {
    await lastFm.connect(apiKey.value.trim(), apiSecret.value.trim())
    apiSecret.value = ''
  } catch {
    // The store exposes the API error in the settings card.
  }
}

async function complete() {
  try {
    await lastFm.complete()
  } catch {
    // The store exposes the API error in the settings card.
  }
}

async function setEnabled(enabled: boolean) {
  try {
    await lastFm.setEnabled(enabled)
  } catch {
    // The store exposes the API error in the settings card.
  }
}

async function disconnect() {
  try {
    await lastFm.disconnect()
  } catch {
    // The store exposes the API error in the settings card.
  }
}

function openAuthorization() {
  const authorizationUrl = lastFm.settings.authorizationUrl
  if (!authorizationUrl) return

  window.open(authorizationUrl, '_blank', 'noopener,noreferrer')
}
</script>

<template>
  <v-card border rounded="xl" class="mt-6">
    <v-card-item class="pa-6 pb-2" prepend-icon="mdi-lastfm">
      <v-card-title>{{ t('settings.lastFm') }}</v-card-title>
      <v-card-subtitle>{{ t('settings.lastFmDescription') }}</v-card-subtitle>
    </v-card-item>

    <v-card-text class="pa-6 pt-4">
      <v-alert v-if="lastFm.error" class="mb-4" type="error" variant="tonal">
        {{ lastFm.error }}
      </v-alert>

      <v-skeleton-loader v-if="lastFm.loading" type="list-item-two-line" />

      <template v-else-if="lastFm.settings.connected">
        <div class="d-flex flex-wrap align-center ga-3 mb-4">
          <v-chip color="success" prepend-icon="mdi-check-circle-outline" variant="tonal">
            {{ t('settings.lastFmConnectedAs', { username: lastFm.settings.username }) }}
          </v-chip>
          <v-btn
            color="error"
            :loading="lastFm.saving"
            size="small"
            variant="text"
            @click="disconnect"
          >
            {{ t('settings.lastFmDisconnect') }}
          </v-btn>
        </div>
        <v-switch
          color="primary"
          :disabled="lastFm.saving"
          :hint="t('settings.lastFmScrobblingHint')"
          :label="t('settings.lastFmScrobbling')"
          :model-value="lastFm.settings.enabled"
          persistent-hint
          @update:model-value="setEnabled(Boolean($event))"
        />
      </template>

      <template v-else>
        <v-alert class="mb-5" type="info" variant="tonal">
          {{ t('settings.lastFmCredentialsHint') }}
          <a href="https://www.last.fm/api/account/create" rel="noreferrer" target="_blank">
            {{ t('settings.lastFmCreateApiAccount') }}
          </a>
        </v-alert>

        <v-text-field
          v-model="apiKey"
          autocomplete="off"
          :disabled="lastFm.saving"
          :label="t('settings.lastFmApiKey')"
        />
        <v-text-field
          v-model="apiSecret"
          autocomplete="new-password"
          :disabled="lastFm.saving"
          :label="t('settings.lastFmSharedSecret')"
          type="password"
        />
        <v-btn
          color="primary"
          :disabled="apiKey.trim().length !== 32 || apiSecret.trim().length !== 32"
          :loading="lastFm.saving"
          prepend-icon="mdi-link-variant"
          variant="flat"
          @click="connect"
        >
          {{ t('settings.lastFmStartConnection') }}
        </v-btn>

        <v-divider v-if="lastFm.settings.authorizationPending" class="my-6" />
        <div v-if="lastFm.settings.authorizationPending">
          <div class="text-subtitle-1 font-weight-bold mb-2">
            {{ t('settings.lastFmAuthorizationTitle') }}
          </div>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ t('settings.lastFmAuthorizationHint') }}
          </p>
          <div class="d-flex flex-wrap ga-3">
            <v-btn
              color="primary"
              prepend-icon="mdi-open-in-new"
              variant="tonal"
              @click="openAuthorization"
            >
              {{ t('settings.lastFmAuthorize') }}
            </v-btn>
            <v-btn
              :loading="lastFm.saving"
              prepend-icon="mdi-check"
              variant="flat"
              @click="complete"
            >
              {{ t('settings.lastFmComplete') }}
            </v-btn>
          </div>
        </div>
      </template>
    </v-card-text>
  </v-card>
</template>

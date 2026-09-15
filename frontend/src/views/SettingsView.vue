<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import AudioIntelligenceSettings from '@/components/AudioIntelligenceSettings.vue'
import CollectionAssistantSettings from '@/components/CollectionAssistantSettings.vue'
import DiscogsSettings from '@/components/DiscogsSettings.vue'
import GeneralSettings from '@/components/GeneralSettings.vue'
import LanAccessSettings from '@/components/LanAccessSettings.vue'
import LastFmDeliveryLog from '@/components/LastFmDeliveryLog.vue'
import LastFmSettings from '@/components/LastFmSettings.vue'
import MediaLibrarySettings from '@/components/MediaLibrarySettings.vue'
import MetadataSettings from '@/components/MetadataSettings.vue'
import OnlineEnrichmentSettings from '@/components/OnlineEnrichmentSettings.vue'
import PageHeader from '@/components/PageHeader.vue'
import PlaylistExportSettings from '@/components/PlaylistExportSettings.vue'
import SystemHealthSettings from '@/components/SystemHealthSettings.vue'
import { useAdminAccessStore } from '@/stores/adminAccess'
import { useLibraryRootsStore } from '@/stores/libraryRoots'
import { useScanRunsStore } from '@/stores/scanRuns'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const adminAccess = useAdminAccessStore()
const libraryRoots = useLibraryRootsStore()
const scanRuns = useScanRunsStore()
const localBrowser = ['localhost', '127.0.0.1', '::1', '[::1]'].includes(window.location.hostname)
const canAccessProtectedSettings = computed(() => localBrowser || adminAccess.hasToken)
const settingsTabs = new Set([
  'general',
  'media-library',
  'metadata',
  'playlists',
  'connections',
  'intelligence',
  'assistant',
  'system',
  'security',
])
const protectedSettingsTabs = new Set([
  'media-library',
  'metadata',
  'playlists',
  'connections',
  'intelligence',
  'assistant',
  'system',
])
const activeSettingsTab = computed({
  get: () => availableSettingsTab(route.query.tab),
  set: (tab: string) => {
    if (!settingsTabs.has(tab) || tab === route.query.tab) return

    void router.push({
      name: 'settings',
      query: { ...route.query, tab },
    })
  },
})

onMounted(normalizeSettingsTabAddress)

function handleAdminAccessChanged() {
  if (canAccessProtectedSettings.value) return

  libraryRoots.clear()
  scanRuns.clear()
  activeSettingsTab.value = 'security'
}

function availableSettingsTab(value: unknown) {
  const tab = typeof value === 'string' && settingsTabs.has(value) ? value : 'general'

  return protectedSettingsTabs.has(tab) && !canAccessProtectedSettings.value ? 'security' : tab
}

async function normalizeSettingsTabAddress() {
  if (route.query.tab === undefined || route.query.tab === activeSettingsTab.value) return

  await router.replace({
    name: 'settings',
    query: { ...route.query, tab: activeSettingsTab.value },
  })
}
</script>

<template>
  <PageHeader :title="t('settings.title')" :description="t('settings.description')" icon="mdi-cog-outline" />
  <v-tabs
    v-model="activeSettingsTab"
    :aria-label="t('settings.title')"
    color="primary"
    show-arrows
  >
    <v-tab prepend-icon="mdi-tune-variant" value="general">
      {{ t('settings.generalTab') }}
    </v-tab>
    <v-tab :disabled="!canAccessProtectedSettings" prepend-icon="mdi-folder-music-outline" value="media-library">
      {{ t('settings.mediaLibraryTab') }}
    </v-tab>
    <v-tab :disabled="!canAccessProtectedSettings" prepend-icon="mdi-tag-multiple-outline" value="metadata">
      {{ t('settings.metadataTab') }}
    </v-tab>
    <v-tab :disabled="!canAccessProtectedSettings" prepend-icon="mdi-playlist-music-outline" value="playlists">
      {{ t('settings.playlistsTab') }}
    </v-tab>
    <v-tab :disabled="!canAccessProtectedSettings" prepend-icon="mdi-connection" value="connections">
      {{ t('settings.connectionsTab') }}
    </v-tab>
    <v-tab :disabled="!canAccessProtectedSettings" prepend-icon="mdi-brain" value="intelligence">
      {{ t('settings.intelligenceTab') }}
    </v-tab>
    <v-tab :disabled="!canAccessProtectedSettings" prepend-icon="mdi-message-processing-outline" value="assistant">
      {{ t('settings.collectionAssistantTab') }}
    </v-tab>
    <v-tab :disabled="!canAccessProtectedSettings" prepend-icon="mdi-heart-pulse" value="system">
      {{ t('settings.systemTab') }}
    </v-tab>
    <v-tab prepend-icon="mdi-shield-lock-outline" value="security">
      {{ t('settings.securityTab') }}
    </v-tab>
  </v-tabs>

  <GeneralSettings v-if="activeSettingsTab === 'general'" />

  <LanAccessSettings
    v-if="activeSettingsTab === 'security'"
    @changed="handleAdminAccessChanged"
  />

  <div
    v-if="canAccessProtectedSettings"
    v-show="activeSettingsTab === 'media-library'"
  >
    <MediaLibrarySettings :key="adminAccess.revision" />
  </div>

  <MetadataSettings
    v-if="activeSettingsTab === 'metadata' && canAccessProtectedSettings"
    :key="adminAccess.revision"
  />

  <PlaylistExportSettings
    v-if="activeSettingsTab === 'playlists' && canAccessProtectedSettings"
    :key="adminAccess.revision"
  />

  <template v-if="activeSettingsTab === 'connections' && canAccessProtectedSettings">
    <OnlineEnrichmentSettings :key="`enrichment-${adminAccess.revision}`" />
    <DiscogsSettings :key="`discogs-${adminAccess.revision}`" />
    <LastFmSettings :key="`lastfm-${adminAccess.revision}`" />
    <LastFmDeliveryLog :key="`lastfm-deliveries-${adminAccess.revision}`" />
  </template>

  <AudioIntelligenceSettings
    v-if="activeSettingsTab === 'intelligence' && canAccessProtectedSettings"
    :key="adminAccess.revision"
  />

  <CollectionAssistantSettings
    v-if="activeSettingsTab === 'assistant' && canAccessProtectedSettings"
    :key="adminAccess.revision"
  />

  <SystemHealthSettings
    v-if="activeSettingsTab === 'system' && canAccessProtectedSettings"
    :key="adminAccess.revision"
  />
</template>

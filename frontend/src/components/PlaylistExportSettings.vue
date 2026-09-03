<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import FolderBrowserDialog from '@/components/FolderBrowserDialog.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import {
  type PlaylistExportFormat,
  type PlaylistExportLocation,
  usePlaylistExportSettingsStore,
} from '@/stores/playlistExportSettings'

const { t } = useI18n()
const playlistExportSettings = usePlaylistExportSettingsStore()
const defaultFormat = ref<PlaylistExportFormat>('m3u8')
const synchronizePlaylists = ref(false)
const locationDialog = ref(false)
const folderBrowserDialog = ref(false)
const removeDialog = ref(false)
const editedLocationId = ref<number | null>(null)
const locationToRemove = ref<PlaylistExportLocation | null>(null)
const saved = ref(false)
const savedMessage = ref('')
let synchronizationPollTimer: ReturnType<typeof setTimeout> | null = null
const locationForm = reactive({
  name: '',
  path: '',
  makeDefault: false,
})
const synchronizationProgress = computed(() => {
  const synchronization = playlistExportSettings.settings.synchronization
  if (synchronization.playlistCount === 0) return 0

  return (synchronization.syncedCount / synchronization.playlistCount) * 100
})

onMounted(async () => {
  await playlistExportSettings.load()
  defaultFormat.value = playlistExportSettings.settings.defaultFormat
  synchronizePlaylists.value = playlistExportSettings.settings.synchronizePlaylists
})

watch(
  () => [
    playlistExportSettings.settings.synchronizePlaylists,
    playlistExportSettings.settings.synchronization.pendingCount,
  ],
  scheduleSynchronizationPoll,
)

onBeforeUnmount(stopSynchronizationPoll)

function scheduleSynchronizationPoll() {
  stopSynchronizationPoll()
  if (
    !playlistExportSettings.settings.synchronizePlaylists
    || playlistExportSettings.settings.synchronization.pendingCount === 0
  ) {
    return
  }

  synchronizationPollTimer = setTimeout(async () => {
    await playlistExportSettings.refreshSynchronization()
    scheduleSynchronizationPoll()
  }, 3000)
}

function stopSynchronizationPoll() {
  if (synchronizationPollTimer !== null) {
    clearTimeout(synchronizationPollTimer)
    synchronizationPollTimer = null
  }
}

async function saveDefaults() {
  try {
    await playlistExportSettings.saveDefaults(defaultFormat.value, synchronizePlaylists.value)
    savedMessage.value = t('settings.playlistDefaultsSaved')
    saved.value = true
  } catch {
    // The store displays the request error.
  }
}

async function retryFailedSynchronization() {
  try {
    await playlistExportSettings.retryFailedSynchronization()
    savedMessage.value = t('settings.playlistSynchronizationRetryQueued')
    saved.value = true
  } catch {
    // The store displays the request error.
  }
}

function openAddLocation() {
  editedLocationId.value = null
  Object.assign(locationForm, { name: '', path: '', makeDefault: false })
  locationDialog.value = true
}

function openEditLocation(location: PlaylistExportLocation) {
  editedLocationId.value = location.id
  Object.assign(locationForm, {
    name: location.name,
    path: location.path,
    makeDefault: location.isDefault,
  })
  locationDialog.value = true
}

function selectFolder(path: string) {
  locationForm.path = path
  if (!locationForm.name.trim()) {
    locationForm.name = folderName(path)
  }
}

async function saveLocation() {
  const input = {
    name: locationForm.name.trim(),
    path: locationForm.path.trim(),
    makeDefault: locationForm.makeDefault,
    createSubfolder: isFilesystemRoot(locationForm.path.trim()),
  }

  try {
    if (editedLocationId.value === null) {
      await playlistExportSettings.createLocation(input)
    } else {
      await playlistExportSettings.updateLocation(editedLocationId.value, input)
    }
    locationDialog.value = false
    savedMessage.value = t('settings.playlistExportLocationSaved')
    saved.value = true
  } catch {
    // Keep the dialog open and display the store error.
  }
}

async function setDefaultLocation(location: PlaylistExportLocation) {
  try {
    await playlistExportSettings.setDefaultLocation(location.id)
    savedMessage.value = t('settings.playlistDefaultsSaved')
    saved.value = true
  } catch {
    // The store displays the request error.
  }
}

function confirmRemoveLocation(location: PlaylistExportLocation) {
  locationToRemove.value = location
  removeDialog.value = true
}

async function removeLocation() {
  if (!locationToRemove.value) return

  try {
    await playlistExportSettings.removeLocation(locationToRemove.value.id)
    removeDialog.value = false
    locationToRemove.value = null
    savedMessage.value = t('settings.playlistExportLocationRemoved')
    saved.value = true
  } catch {
    // Keep the dialog open and display the store error.
  }
}

function folderName(path: string) {
  const normalized = path.replaceAll('\\', '/').replace(/\/+$/, '')
  const name = normalized.split('/').pop()

  return name || normalized || t('settings.playlistExportLocations')
}

function isFilesystemRoot(path: string) {
  const normalized = path.replaceAll('\\', '/')

  return normalized === '/'
    || /^[A-Za-z]:\/?$/.test(normalized)
    || /^\/\/[^/]+\/[^/]+\/?$/.test(normalized)
}
</script>

<template>
  <div>
    <v-alert v-if="playlistExportSettings.error" class="mt-6" type="error" variant="tonal">
      {{ playlistExportSettings.error }}
    </v-alert>

    <v-card border rounded="xl" class="mt-6">
      <v-card-item class="pa-6 pb-2" prepend-icon="mdi-file-music-outline">
        <v-card-title>{{ t('settings.playlistExportSettings') }}</v-card-title>
        <v-card-subtitle>{{ t('settings.playlistExportSettingsDescription') }}</v-card-subtitle>
      </v-card-item>
      <v-card-text class="pa-6 pt-4">
        <v-skeleton-loader v-if="playlistExportSettings.loading" type="list-item-two-line" />
        <template v-else>
          <v-select
            v-model="defaultFormat"
            class="format-field"
            :items="[
              { title: 'M3U8', value: 'm3u8' },
              { title: 'M3U', value: 'm3u' },
            ]"
            :label="t('settings.playlistDefaultFormat')"
            :hint="t('settings.playlistDefaultFormatHint')"
            persistent-hint
          />
          <v-switch
            v-model="synchronizePlaylists"
            class="mt-4"
            color="primary"
            :disabled="playlistExportSettings.settings.locations.length === 0"
            :label="t('settings.synchronizePlaylists')"
            :hint="
              playlistExportSettings.settings.locations.length
                ? t('settings.synchronizePlaylistsHint')
                : t('settings.synchronizePlaylistsNeedsFolder')
            "
            persistent-hint
          />
          <v-alert
            v-if="playlistExportSettings.settings.synchronizePlaylists"
            class="mt-4"
            icon="mdi-sync"
            type="info"
            variant="tonal"
          >
            <div>
              {{
                t('settings.playlistSynchronizationStatus', {
                  synced: playlistExportSettings.settings.synchronization.syncedCount,
                  total: playlistExportSettings.settings.synchronization.playlistCount,
                  failed: playlistExportSettings.settings.synchronization.failedCount,
                })
              }}
            </div>
            <v-progress-linear
              v-if="playlistExportSettings.settings.synchronization.playlistCount > 0"
              class="mt-3"
              color="primary"
              height="8"
              :model-value="synchronizationProgress"
              rounded
            />
            <div
              v-if="playlistExportSettings.settings.synchronization.pendingCount > 0"
              class="text-caption mt-2"
            >
              {{
                t('settings.playlistSynchronizationPending', {
                  count: playlistExportSettings.settings.synchronization.pendingCount,
                })
              }}
            </div>
            <div
              v-if="playlistExportSettings.settings.synchronization.failedCount > 0"
              class="d-flex justify-end mt-3"
            >
              <v-btn
                :loading="playlistExportSettings.retrying"
                prepend-icon="mdi-refresh"
                size="small"
                variant="tonal"
                @click="retryFailedSynchronization"
              >
                {{ t('settings.retryFailedPlaylistSynchronization') }}
              </v-btn>
            </div>
          </v-alert>
          <div class="d-flex justify-end mt-4">
            <v-btn
              color="primary"
              :loading="playlistExportSettings.saving"
              prepend-icon="mdi-content-save-outline"
              variant="flat"
              @click="saveDefaults"
            >
              {{ t('settings.savePlaylistDefaults') }}
            </v-btn>
          </div>
        </template>
      </v-card-text>
    </v-card>

    <v-card border rounded="xl" class="mt-6">
      <v-card-item class="location-header pa-6 pb-2">
        <v-card-title>{{ t('settings.playlistExportLocations') }}</v-card-title>
        <v-card-subtitle>{{ t('settings.playlistExportLocationsDescription') }}</v-card-subtitle>
        <template #append>
          <v-btn
            color="primary"
            prepend-icon="mdi-folder-plus-outline"
            variant="flat"
            @click="openAddLocation"
          >
            {{ t('settings.addPlaylistExportLocation') }}
          </v-btn>
        </template>
      </v-card-item>
      <v-card-text class="pa-6 pt-4">
        <v-skeleton-loader v-if="playlistExportSettings.loading" type="list-item-two-line@2" />
        <v-list v-else-if="playlistExportSettings.settings.locations.length" lines="two">
          <v-list-item
            v-for="location in playlistExportSettings.settings.locations"
            :key="location.id"
            prepend-icon="mdi-folder-music-outline"
          >
            <v-list-item-title class="d-flex flex-wrap align-center ga-2">
              <span class="font-weight-bold">{{ location.name }}</span>
              <v-chip
                v-if="location.isDefault"
                color="primary"
                prepend-icon="mdi-star"
                size="small"
                variant="tonal"
              >
                {{ t('settings.playlistExportDefault') }}
              </v-chip>
            </v-list-item-title>
            <v-list-item-subtitle>{{ location.path }}</v-list-item-subtitle>
            <template #append>
              <div class="d-flex align-center ga-1">
                <TooltipIconButton
                  v-if="!location.isDefault"
                  :aria-label="t('settings.setPlaylistExportDefault')"
                  icon="mdi-star-outline"
                  :text="t('settings.setPlaylistExportDefault')"
                  variant="text"
                  @click="setDefaultLocation(location)"
                />
                <TooltipIconButton
                  :aria-label="t('settings.editPlaylistExportLocation')"
                  icon="mdi-pencil-outline"
                  :text="t('settings.editPlaylistExportLocation')"
                  variant="text"
                  @click="openEditLocation(location)"
                />
                <TooltipIconButton
                  :aria-label="t('settings.removePlaylistExportLocation')"
                  color="error"
                  icon="mdi-delete-outline"
                  :text="t('settings.removePlaylistExportLocation')"
                  variant="text"
                  @click="confirmRemoveLocation(location)"
                />
              </div>
            </template>
          </v-list-item>
        </v-list>
        <v-empty-state
          v-else
          icon="mdi-folder-music-outline"
          :headline="t('settings.noPlaylistExportLocations')"
          :text="t('settings.noPlaylistExportLocationsDescription')"
        />
      </v-card-text>
    </v-card>

    <v-dialog v-model="locationDialog" max-width="680">
      <v-card rounded="xl">
        <v-card-title class="pa-6 pb-2">
          {{
            editedLocationId === null
              ? t('settings.addPlaylistExportLocation')
              : t('settings.editPlaylistExportLocation')
          }}
        </v-card-title>
        <v-card-text class="pa-6 pt-4">
          <v-alert v-if="playlistExportSettings.error" class="mb-4" type="error" variant="tonal">
            {{ playlistExportSettings.error }}
          </v-alert>
          <v-text-field
            v-model="locationForm.name"
            autofocus
            :label="t('settings.playlistExportLocationName')"
          />
          <div class="d-flex align-start ga-2 mt-2 location-path-row">
            <v-text-field
              v-model="locationForm.path"
              :label="t('settings.playlistExportLocationPath')"
              :hint="t('settings.playlistExportLocationPathHint')"
              persistent-hint
            />
            <v-btn
              class="mt-1"
              prepend-icon="mdi-folder-open-outline"
              variant="tonal"
              @click="folderBrowserDialog = true"
            >
              {{ t('settings.browseFolders') }}
            </v-btn>
          </div>
          <v-switch
            v-model="locationForm.makeDefault"
            color="primary"
            :label="t('settings.makePlaylistExportDefault')"
          />
        </v-card-text>
        <v-card-actions class="px-6 pb-6">
          <v-spacer />
          <v-btn variant="text" @click="locationDialog = false">
            {{ t('settings.cancel') }}
          </v-btn>
          <v-btn
            color="primary"
            :disabled="!locationForm.name.trim() || !locationForm.path.trim()"
            :loading="playlistExportSettings.saving"
            variant="flat"
            @click="saveLocation"
          >
            {{ t('settings.saveChanges') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <FolderBrowserDialog
      v-model="folderBrowserDialog"
      :initial-path="locationForm.path"
      :title="t('settings.playlistExportFolderBrowserTitle')"
      @select="selectFolder"
    />

    <v-dialog v-model="removeDialog" max-width="500">
      <v-card rounded="xl">
        <v-card-title class="pa-6 pb-2">
          {{ t('settings.removePlaylistExportLocationTitle') }}
        </v-card-title>
        <v-card-text class="pa-6 pt-4">
          {{ t('settings.removePlaylistExportLocationWarning', { name: locationToRemove?.name }) }}
        </v-card-text>
        <v-card-actions class="px-6 pb-6">
          <v-spacer />
          <v-btn variant="text" @click="removeDialog = false">
            {{ t('settings.cancel') }}
          </v-btn>
          <v-btn
            color="error"
            :loading="playlistExportSettings.saving"
            variant="flat"
            @click="removeLocation"
          >
            {{ t('settings.remove') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="saved" color="success" timeout="3000">
      {{ savedMessage }}
    </v-snackbar>
  </div>
</template>

<style scoped>
.format-field {
  max-width: 18rem;
}

@media (max-width: 600px) {
  .location-header :deep(.v-card-item__append) {
    align-self: flex-start;
  }

  .location-path-row {
    align-items: stretch !important;
    flex-direction: column;
  }

  .location-path-row .v-btn {
    align-self: flex-start;
    margin-top: 0 !important;
  }
}
</style>

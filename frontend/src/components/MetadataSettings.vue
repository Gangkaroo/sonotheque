<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import FolderBrowserDialog from '@/components/FolderBrowserDialog.vue'
import { useMetadataBackupSettingsStore } from '@/stores/metadataBackupSettings'
import { usePlaybackStatisticsSettingsStore } from '@/stores/playbackStatisticsSettings'

const { t } = useI18n()
const metadataBackupSettings = useMetadataBackupSettingsStore()
const playbackStatisticsSettings = usePlaybackStatisticsSettingsStore()
const backupFolderBrowserDialog = ref(false)
const backupForm = reactive({ enabled: false, path: '', retentionDays: 30 })
const backupSaved = ref(false)

onMounted(async () => {
  await Promise.all([
    playbackStatisticsSettings.load(),
    metadataBackupSettings.load(),
  ])
  Object.assign(backupForm, metadataBackupSettings.settings)
})

/** @param {boolean | null} enabled */
async function setSynchronizePlayStatistics(enabled) {
  if (enabled === null) return

  await playbackStatisticsSettings.setSynchronizeWithFileTags(enabled)
}

/** @param {string} path */
function selectBackupFolder(path) {
  backupForm.path = path
}

async function saveBackupSettings() {
  try {
    await metadataBackupSettings.save({ ...backupForm })
    Object.assign(backupForm, metadataBackupSettings.settings)
    backupSaved.value = true
  } catch {
    // The store exposes the API error in the settings card.
  }
}
</script>

<template>
  <div>
    <v-card border rounded="xl" class="mt-6">
      <v-card-item class="pa-6 pb-2" prepend-icon="mdi-headphones">
        <v-card-title>{{ t('settings.listeningStatistics') }}</v-card-title>
        <v-card-subtitle>{{ t('settings.listeningStatisticsDescription') }}</v-card-subtitle>
      </v-card-item>
      <v-card-text class="pa-6 pt-4">
        <v-alert v-if="playbackStatisticsSettings.error" class="mb-4" type="error" variant="tonal">
          {{ playbackStatisticsSettings.error }}
        </v-alert>
        <v-skeleton-loader v-if="playbackStatisticsSettings.loading" type="list-item-two-line" />
        <v-switch
          v-else
          color="primary"
          :disabled="playbackStatisticsSettings.saving"
          :hint="t('settings.synchronizePlayStatisticsHint')"
          :label="t('settings.synchronizePlayStatistics')"
          :loading="playbackStatisticsSettings.saving"
          :model-value="playbackStatisticsSettings.settings.synchronizeWithFileTags"
          persistent-hint
          @update:model-value="setSynchronizePlayStatistics"
        />
      </v-card-text>
    </v-card>

    <v-card border rounded="xl" class="mt-6">
      <v-card-item class="pa-6 pb-2" prepend-icon="mdi-backup-restore">
        <v-card-title>{{ t('settings.metadataBackups') }}</v-card-title>
        <v-card-subtitle>{{ t('settings.metadataBackupsDescription') }}</v-card-subtitle>
      </v-card-item>
      <v-card-text class="pa-6 pt-4">
        <v-alert v-if="metadataBackupSettings.error" class="mb-4" type="error" variant="tonal">
          {{ metadataBackupSettings.error }}
        </v-alert>
        <v-skeleton-loader v-if="metadataBackupSettings.loading" type="list-item-two-line" />
        <template v-else>
          <v-switch
            v-model="backupForm.enabled"
            color="primary"
            :label="t('settings.enableMetadataBackups')"
            :hint="t('settings.enableMetadataBackupsHint')"
            persistent-hint
          />
          <div class="d-flex align-start ga-2 mt-3 backup-path-row">
            <v-text-field
              v-model="backupForm.path"
              :label="t('settings.metadataBackupPath')"
              :hint="t('settings.metadataBackupPathHint')"
              persistent-hint
            />
            <v-btn
              class="mt-1"
              prepend-icon="mdi-folder-open-outline"
              variant="tonal"
              @click="backupFolderBrowserDialog = true"
            >
              {{ t('settings.browseFolders') }}
            </v-btn>
          </div>
          <v-text-field
            v-model.number="backupForm.retentionDays"
            class="backup-retention-field mt-3"
            inputmode="numeric"
            :label="t('settings.metadataBackupRetention')"
            :hint="t('settings.metadataBackupRetentionHint')"
            min="1"
            max="3650"
            persistent-hint
            type="number"
          />
          <div class="d-flex justify-end mt-4">
            <v-btn
              color="primary"
              :disabled="!backupForm.path.trim()"
              :loading="metadataBackupSettings.saving"
              prepend-icon="mdi-content-save-outline"
              variant="flat"
              @click="saveBackupSettings"
            >
              {{ t('settings.saveBackupSettings') }}
            </v-btn>
          </div>
        </template>
      </v-card-text>
    </v-card>

    <FolderBrowserDialog
      v-model="backupFolderBrowserDialog"
      :initial-path="backupForm.path"
      :title="t('settings.metadataBackupFolderBrowserTitle')"
      @select="selectBackupFolder"
    />

    <v-snackbar v-model="backupSaved" color="success" timeout="3000">
      {{ t('settings.metadataBackupSettingsSaved') }}
    </v-snackbar>
  </div>
</template>

<style scoped>
.backup-retention-field {
  max-width: 18rem;
}

@media (max-width: 600px) {
  .backup-path-row {
    align-items: stretch !important;
    flex-direction: column;
  }

  .backup-path-row .v-btn {
    align-self: flex-start;
    margin-top: 0 !important;
  }
}
</style>

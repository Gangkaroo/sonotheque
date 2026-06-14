<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@/api/client'
import PageHeader from '@/components/PageHeader.vue'
import { useLibraryRootsStore } from '@/stores/libraryRoots'

/** @typedef {import('@/stores/libraryRoots').LibraryRoot} LibraryRoot */

const { t } = useI18n()
const libraryRoots = useLibraryRootsStore()
const addDialog = ref(false)
const deleteDialog = ref(false)
const rootToDelete = ref(/** @type {LibraryRoot | null} */ (null))
const form = reactive({ name: '', path: '', coverImagePath: 'cover.jpg' })
const fieldErrors = reactive(/** @type {Record<string, string[]>} */ ({}))
const submitError = ref(/** @type {string | null} */ (null))

onMounted(() => libraryRoots.load())

function openAddDialog() {
  Object.assign(form, { name: '', path: '', coverImagePath: 'cover.jpg' })
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  submitError.value = null
  addDialog.value = true
}

async function addRoot() {
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  submitError.value = null

  try {
    await libraryRoots.create({ ...form })
    addDialog.value = false
  } catch (cause) {
    if (cause instanceof ApiError) {
      Object.assign(fieldErrors, cause.violations)
      submitError.value = cause.message
      return
    }
    submitError.value = cause instanceof Error ? cause.message : t('settings.saveError')
  }
}

/** @param {LibraryRoot} root */
function confirmRemove(root) {
  rootToDelete.value = root
  deleteDialog.value = true
}

async function removeRoot() {
  if (!rootToDelete.value) return
  await libraryRoots.remove(rootToDelete.value.id)
  deleteDialog.value = false
  rootToDelete.value = null
}
</script>

<template>
  <PageHeader :title="t('settings.title')" :description="t('settings.description')" icon="mdi-cog-outline" />
  <v-card border rounded="xl">
    <v-card-item class="pa-6 pb-2">
      <v-card-title>{{ t('settings.libraryRoots') }}</v-card-title>
      <v-card-subtitle>{{ t('settings.libraryRootsDescription') }}</v-card-subtitle>
      <template #append>
        <v-btn color="primary" prepend-icon="mdi-folder-plus-outline" variant="flat" @click="openAddDialog">
          {{ t('settings.addRoot') }}
        </v-btn>
      </template>
    </v-card-item>

    <v-card-text class="pa-6 pt-4">
      <v-alert v-if="libraryRoots.error" type="error" variant="tonal" class="mb-4">
        {{ libraryRoots.error }}
      </v-alert>
      <v-skeleton-loader v-if="libraryRoots.loading" type="list-item-two-line@2" />
      <v-list v-else-if="libraryRoots.hasRoots" lines="three">
        <v-list-item v-for="root in libraryRoots.roots" :key="root.id" prepend-icon="mdi-harddisk">
          <v-list-item-title class="font-weight-bold">{{ root.name }}</v-list-item-title>
          <v-list-item-subtitle>{{ root.path }}</v-list-item-subtitle>
          <v-list-item-subtitle>{{ t('settings.coverPath') }}: {{ root.coverImagePath }}</v-list-item-subtitle>
          <template #append>
            <v-btn
              :aria-label="t('settings.removeRoot', { name: root.name })"
              color="error"
              icon="mdi-delete-outline"
              variant="text"
              @click="confirmRemove(root)"
            />
          </template>
        </v-list-item>
      </v-list>
      <v-empty-state
        v-else
        icon="mdi-folder-music-outline"
        :headline="t('settings.noRoots')"
        :text="t('settings.noRootsDescription')"
      />
    </v-card-text>
  </v-card>

  <v-dialog v-model="addDialog" max-width="640">
    <v-card :title="t('settings.addRoot')" prepend-icon="mdi-folder-plus-outline">
      <v-card-text>
        <v-alert v-if="submitError" type="error" variant="tonal" class="mb-4">{{ submitError }}</v-alert>
        <v-text-field
          v-model="form.name"
          autofocus
          :error-messages="fieldErrors.name"
          :label="t('settings.rootName')"
          :placeholder="t('settings.rootNameExample')"
        />
        <v-text-field
          v-model="form.path"
          :error-messages="fieldErrors.path"
          :hint="t('settings.rootPathHint')"
          :label="t('settings.rootPath')"
          persistent-hint
          placeholder="D:\\Music"
        />
        <v-text-field
          v-model="form.coverImagePath"
          :error-messages="fieldErrors.coverImagePath"
          :hint="t('settings.coverPathHint')"
          :label="t('settings.coverPath')"
          persistent-hint
          placeholder="cover.jpg"
        />
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="addDialog = false">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="primary" variant="flat" :loading="libraryRoots.saving" @click="addRoot">
          {{ t('settings.saveRoot') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-dialog v-model="deleteDialog" max-width="520">
    <v-card :title="t('settings.removeRootTitle')" prepend-icon="mdi-alert-outline">
      <v-card-text>{{ t('settings.removeRootWarning', { name: rootToDelete?.name }) }}</v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="deleteDialog = false">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="error" variant="flat" @click="removeRoot">{{ t('settings.remove') }}</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

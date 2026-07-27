<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@/api/client'
import FolderBrowserDialog from '@/components/FolderBrowserDialog.vue'
import { useLibraryRootsStore } from '@/stores/libraryRoots'
import type { LibraryRoot } from '@/stores/libraryRoots'

const props = defineProps<{
  modelValue: boolean
  root?: LibraryRoot | null
}>()
const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: [root: LibraryRoot]
}>()

const { t } = useI18n()
const libraryRoots = useLibraryRootsStore()
const folderBrowserDialog = ref(false)
const excludeFolderBrowserDialog = ref(false)
const fieldErrors = reactive<Record<string, string[]>>({})
const submitError = ref<string | null>(null)
const form = reactive({
  name: '',
  path: '',
  coverImagePaths: ['cover.jpg'],
  excludedDirectories: [] as string[],
  watchEnabled: false,
  watchPollIntervalMinutes: 5,
  watchReconcileIntervalMinutes: 1440,
})
const watchPollIntervals = [1, 5, 15, 30, 60]
const watchReconcileIntervals = [
  { title: t('settings.watchReconcileSixHours'), value: 360 },
  { title: t('settings.watchReconcileDaily'), value: 1440 },
  { title: t('settings.watchReconcileWeekly'), value: 10080 },
]

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    Object.assign(form, {
      name: props.root?.name ?? '',
      path: props.root?.path ?? '',
      coverImagePaths: props.root?.coverImagePaths?.length ? [...props.root.coverImagePaths] : ['cover.jpg'],
      excludedDirectories: [...(props.root?.excludedDirectories ?? [])],
      watchEnabled: props.root?.watchEnabled ?? false,
      watchPollIntervalMinutes: props.root?.watchPollIntervalMinutes ?? 5,
      watchReconcileIntervalMinutes: props.root?.watchReconcileIntervalMinutes ?? 1440,
    })
    clearErrors()
  },
)

function clearErrors() {
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
  submitError.value = null
}

function close() {
  emit('update:modelValue', false)
}

function selectFolder(path: string) {
  if (form.path !== path) form.excludedDirectories = []
  form.path = path
  fieldErrors.path = []
}

async function save() {
  clearErrors()
  const input = {
    name: form.name,
    coverImagePaths: form.coverImagePaths.map((path) => path.trim()).filter(Boolean),
    excludedDirectories: [...form.excludedDirectories],
    watchEnabled: form.watchEnabled,
    watchPollIntervalMinutes: form.watchPollIntervalMinutes,
    watchReconcileIntervalMinutes: form.watchReconcileIntervalMinutes,
  }

  try {
    const root = props.root
      ? await libraryRoots.update(props.root.id, input)
      : await libraryRoots.create({ ...input, path: form.path })
    emit('saved', root)
    close()
  } catch (cause) {
    if (cause instanceof ApiError) {
      Object.assign(fieldErrors, cause.violations)
      submitError.value = cause.message
      return
    }
    submitError.value = cause instanceof Error ? cause.message : t('settings.saveError')
  }
}

function addCoverPath() {
  form.coverImagePaths.push('')
}

function removeCoverPath(index: number) {
  if (form.coverImagePaths.length > 1) form.coverImagePaths.splice(index, 1)
}

function selectExcludedFolder(selectedPath: string) {
  const root = form.path.replace(/\\/g, '/').replace(/\/$/, '')
  const selected = selectedPath.replace(/\\/g, '/').replace(/\/$/, '')
  const prefix = `${root}/`
  if (!root || selected.toLocaleLowerCase() === root.toLocaleLowerCase() || !selected.toLocaleLowerCase().startsWith(prefix.toLocaleLowerCase())) {
    submitError.value = t('settings.excludedFolderOutsideRoot')
    return
  }

  const relative = selected.slice(prefix.length)
  if (!form.excludedDirectories.some((path) => path.toLocaleLowerCase() === relative.toLocaleLowerCase())) {
    form.excludedDirectories.push(relative)
  }
  submitError.value = null
}

function removeExcludedFolder(path: string) {
  form.excludedDirectories = form.excludedDirectories.filter((candidate) => candidate !== path)
}
</script>

<template>
  <v-dialog :model-value="modelValue" max-width="640" @update:model-value="emit('update:modelValue', $event)">
    <v-card
      :title="root ? t('settings.editRootTitle') : t('settings.addRoot')"
      :prepend-icon="root ? 'mdi-pencil-outline' : 'mdi-folder-plus-outline'"
    >
      <v-card-text>
        <v-alert v-if="submitError" type="error" variant="tonal" class="mb-4">{{ submitError }}</v-alert>
        <v-text-field
          v-model="form.name"
          autofocus
          :error-messages="fieldErrors.name"
          :label="t('settings.rootName')"
          :placeholder="t('settings.rootNameExample')"
        />
        <div class="d-flex align-start ga-2">
          <v-text-field
            v-model="form.path"
            :error-messages="fieldErrors.path"
            :hint="t('settings.rootPathHint')"
            :label="t('settings.rootPath')"
            persistent-hint
            placeholder="D:/Music"
            :readonly="Boolean(root)"
          />
          <v-btn
            v-if="!root"
            class="mt-1"
            prepend-icon="mdi-folder-open-outline"
            variant="tonal"
            @click="folderBrowserDialog = true"
          >
            {{ t('settings.browseFolders') }}
          </v-btn>
        </div>
        <div class="text-subtitle-2 mb-2">{{ t('settings.coverPaths') }}</div>
        <div v-for="(_coverPath, index) in form.coverImagePaths" :key="index" class="d-flex align-start ga-2">
          <v-text-field
            v-model="form.coverImagePaths[index]"
            :error-messages="index === 0 ? fieldErrors.coverImagePaths : []"
            :hint="index === 0 ? t('settings.coverPathsHint') : undefined"
            :label="t('settings.coverPathNumber', { number: index + 1 })"
            persistent-hint
            placeholder="cover.jpg"
          />
          <v-btn
            class="mt-1"
            :disabled="form.coverImagePaths.length === 1"
            :aria-label="t('settings.removeCoverPath', { number: index + 1 })"
            icon="mdi-delete-outline"
            variant="text"
            @click="removeCoverPath(index)"
          />
        </div>
        <v-btn class="mb-5" prepend-icon="mdi-plus" size="small" variant="tonal" @click="addCoverPath">
          {{ t('settings.addCoverPath') }}
        </v-btn>

        <div class="text-subtitle-2 mb-2">{{ t('settings.excludedFolders') }}</div>
        <div class="d-flex flex-wrap ga-2 mb-3">
          <v-chip
            v-for="directory in form.excludedDirectories"
            :key="directory"
            closable
            @click:close="removeExcludedFolder(directory)"
          >
            {{ directory }}
          </v-chip>
          <span v-if="!form.excludedDirectories.length" class="text-body-2 text-medium-emphasis">
            {{ t('settings.noExcludedFolders') }}
          </span>
        </div>
        <v-btn
          prepend-icon="mdi-folder-remove-outline"
          size="small"
          variant="tonal"
          :disabled="!form.path"
          @click="excludeFolderBrowserDialog = true"
        >
          {{ t('settings.addExcludedFolder') }}
        </v-btn>
        <div class="text-caption text-medium-emphasis mt-2">{{ t('settings.excludedFoldersHint') }}</div>
        <div v-if="fieldErrors.excludedDirectories?.length" class="text-caption text-error mt-1">
          {{ fieldErrors.excludedDirectories.join(' ') }}
        </div>

        <v-divider class="my-5" />
        <v-switch
          v-model="form.watchEnabled"
          color="primary"
          :label="t('settings.watchLibraryRoot')"
          :hint="t('settings.watchLibraryRootHint')"
          persistent-hint
        />
        <div v-if="form.watchEnabled" class="watch-settings mt-3">
          <v-select
            v-model="form.watchPollIntervalMinutes"
            :items="watchPollIntervals"
            :error-messages="fieldErrors.watchPollIntervalMinutes"
            :label="t('settings.watchPollInterval')"
            :hint="t('settings.watchPollIntervalHint')"
            persistent-hint
          />
          <v-select
            v-model="form.watchReconcileIntervalMinutes"
            :items="watchReconcileIntervals"
            :error-messages="fieldErrors.watchReconcileIntervalMinutes"
            :label="t('settings.watchReconcileInterval')"
            :hint="t('settings.watchReconcileIntervalHint')"
            persistent-hint
          />
        </div>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="close">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="primary" variant="flat" :loading="libraryRoots.saving" @click="save">
          {{ root ? t('settings.saveChanges') : t('settings.saveRoot') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <FolderBrowserDialog v-model="folderBrowserDialog" :initial-path="form.path" @select="selectFolder" />
  <FolderBrowserDialog
    v-model="excludeFolderBrowserDialog"
    :initial-path="form.path"
    :title="t('settings.excludedFolderBrowserTitle')"
    @select="selectExcludedFolder"
  />
</template>

<style scoped>
.watch-settings {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

@media (max-width: 600px) {
  .watch-settings {
    grid-template-columns: 1fr;
  }
}
</style>

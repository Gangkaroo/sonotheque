<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'
import AdditionalMetadataTagRemovalList from '@/components/AdditionalMetadataTagRemovalList.vue'
import { useBackgroundJobPolling } from '@/composables/useBackgroundJobPolling'
import type { TrackDetail } from '@/stores/catalog'

const props = defineProps<{
  modelValue: boolean
  track: TrackDetail
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  completed: []
}>()

const { t } = useI18n()
const step = ref<'form' | 'preview' | 'queued'>('form')
const loading = ref(false)
const error = ref<string | null>(null)
const preview = ref<MetadataPreview | null>(null)
const removedTagKeys = ref<string[]>([])
const form = reactive({
  title: '',
  artistNames: [] as string[],
  composers: [] as string[],
  performers: [] as string[],
  genres: [] as string[],
  comment: '',
  trackNumber: '',
  discNumber: '',
  year: '',
})

interface MetadataChange {
  field: 'title' | 'artistNames' | 'composers' | 'performers' | 'genres' | 'comment' | 'trackNumber' | 'discNumber' | 'year' | 'removedTagKeys'
  current: string | number | string[] | null
  proposed: string | number | string[] | null
}

interface MetadataValues {
  title: string
  artistNames: string[]
  composers: string[]
  performers: string[]
  genres: string[]
  comment: string | null
  trackNumber: number | null
  discNumber: number | null
  year: number | null
  removedTagKeys: string[]
}

interface MetadataPreview {
  trackId: number
  file: string
  format: string
  supported: boolean
  supportIssue?: string | null
  fingerprint: string
  values: MetadataValues
  changes: MetadataChange[]
}

interface MetadataBackup {
  id: number
  path: string
  expiresAt: string | null
  restoredAt: string | null
  deletedAt: string | null
}

interface MetadataEditJob {
  id: number
  trackId: number
  status: 'pending' | 'running' | 'completed' | 'failed'
  error?: string | null
  failureReason?: string | null
  backup?: MetadataBackup | null
}

const isOpen = computed({
  get: () => props.modelValue,
  set: value => emit('update:modelValue', value),
})
const canEdit = computed(() => props.track.mediaFile?.relativePath.toLowerCase().endsWith('.mp3') ?? false)
const additionalTags = computed(() => props.track.mediaFile?.additionalTags ?? [])

const {
  job,
  start: startPolling,
  reset: resetPolling,
} = useBackgroundJobPolling<MetadataEditJob>({
  request: (id, signal) => apiRequest<MetadataEditJob>(`/metadata-edits/${id}`, { signal }),
  isComplete: value => value.status === 'completed',
  isTerminal: value => value.status === 'failed',
  onComplete: () => {
    isOpen.value = false
    emit('completed')
  },
  onTerminal: value => {
    error.value = value.failureReason ?? value.error ?? t('tracks.metadataEditFailed')
  },
  onError: cause => {
    error.value = cause instanceof Error ? cause.message : t('tracks.metadataEditFailed')
  },
})

function initialize() {
  resetPolling()
  Object.assign(form, {
    title: props.track.title,
    artistNames: props.track.artists.map(artist => artist.name),
    composers: [...props.track.composers],
    performers: [...props.track.performers],
    genres: props.track.genres.map(genre => genre.name),
    comment: props.track.comment ?? '',
    trackNumber: props.track.trackNumber?.toString() ?? '',
    discNumber: props.track.discNumber?.toString() ?? '',
    year: props.track.year?.toString() ?? '',
  })
  step.value = 'form'
  preview.value = null
  error.value = null
  removedTagKeys.value = []
}

function metadataValues(): MetadataValues {
  return {
    title: form.title.trim(),
    artistNames: cleanNames(form.artistNames),
    composers: cleanNames(form.composers),
    performers: cleanNames(form.performers),
    genres: cleanNames(form.genres),
    comment: form.comment.trim() || null,
    trackNumber: nullableInteger(form.trackNumber),
    discNumber: nullableInteger(form.discNumber),
    year: nullableInteger(form.year),
    removedTagKeys: [...removedTagKeys.value],
  }
}

function cleanNames(names: string[]) {
  return names.map(name => name.trim()).filter(Boolean)
}

function nullableInteger(value: string) {
  const trimmed = value.trim()
  return trimmed === '' ? null : Number(trimmed)
}

async function previewEdit() {
  loading.value = true
  error.value = null
  try {
    preview.value = await apiRequest<MetadataPreview>(`/tracks/${props.track.id}/metadata/preview`, {
      method: 'POST',
      body: JSON.stringify(metadataValues()),
    })
    step.value = 'preview'
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('tracks.metadataEditFailed')
  } finally {
    loading.value = false
  }
}

async function queueEdit() {
  if (!preview.value) return
  loading.value = true
  error.value = null
  try {
    const queuedJob = await apiRequest<MetadataEditJob>(`/tracks/${props.track.id}/metadata-edits`, {
      method: 'POST',
      body: JSON.stringify({ ...preview.value.values, fingerprint: preview.value.fingerprint }),
    })
    step.value = 'queued'
    startPolling(queuedJob)
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('tracks.metadataEditFailed')
  } finally {
    loading.value = false
  }
}

function metadataFieldLabel(field: MetadataChange['field']) {
  return t({
    title: 'tracks.metadataTitle',
    artistNames: 'tracks.artists',
    composers: 'tracks.composers',
    performers: 'tracks.performers',
    genres: 'tracks.genres',
    comment: 'tracks.comment',
    trackNumber: 'tracks.trackNumber',
    discNumber: 'tracks.discNumber',
    year: 'tracks.year',
    removedTagKeys: 'tracks.removeAdditionalTags',
  }[field])
}

function metadataValue(value: MetadataChange['current']) {
  if (Array.isArray(value)) return value.length ? value.join(', ') : '-'
  return value === null || value === '' ? '-' : String(value)
}

watch(() => props.modelValue, (open) => {
  if (open) initialize()
}, { immediate: true })

watch(() => props.track.id, () => {
  resetPolling()
  if (props.modelValue) initialize()
})
</script>

<template>
  <v-dialog v-model="isOpen" max-width="680" persistent>
    <v-card prepend-icon="mdi-tag-edit-outline" :title="t('tracks.editMetadata')">
      <v-card-text>
        <v-alert v-if="error" class="mb-4" type="error" variant="tonal">{{ error }}</v-alert>

        <template v-if="step === 'form'">
          <v-alert v-if="!canEdit" class="mb-4" type="warning" variant="tonal">
            {{ t('tracks.metadataMp3Only') }}
          </v-alert>
          <v-text-field v-model="form.title" :label="t('tracks.metadataTitle')" maxlength="512" />
          <v-combobox v-model="form.artistNames" chips closable-chips :label="t('tracks.artists')" multiple />
          <v-combobox
            v-model="form.composers"
            chips
            closable-chips
            clearable
            :label="t('tracks.composers')"
            multiple
          />
          <v-combobox
            v-model="form.performers"
            chips
            closable-chips
            clearable
            :label="t('tracks.performers')"
            multiple
          />
          <v-combobox
            v-model="form.genres"
            chips
            closable-chips
            clearable
            :label="t('tracks.genres')"
            multiple
          />
          <v-textarea v-model="form.comment" auto-grow :label="t('tracks.comment')" maxlength="10000" rows="2" />
          <v-row>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.trackNumber" inputmode="numeric" :label="t('tracks.trackNumber')" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.discNumber" inputmode="numeric" :label="t('tracks.discNumber')" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model="form.year" inputmode="numeric" :label="t('tracks.year')" />
            </v-col>
          </v-row>
          <template v-if="additionalTags.length">
            <v-divider class="mb-4" />
            <AdditionalMetadataTagRemovalList
              v-model="removedTagKeys"
              :hint="t('tracks.removeAdditionalTagsHint')"
              :tags="additionalTags"
              :title="t('tracks.removeAdditionalTags')"
            />
          </template>
          <div class="text-caption text-medium-emphasis">{{ t('tracks.metadataPreviewHint') }}</div>
        </template>

        <template v-else-if="step === 'preview' && preview">
          <v-alert v-if="!preview.supported" class="mb-4" type="warning" variant="tonal">
            {{ preview.supportIssue
              ? t(`tracks.metadataSupportIssues.${preview.supportIssue}`)
              : t('tracks.metadataMp3Only') }}
          </v-alert>
          <div class="text-body-2 text-medium-emphasis mb-3">{{ preview.file }}</div>
          <v-list v-if="preview.changes.length" border rounded>
            <v-list-item v-for="change in preview.changes" :key="change.field">
              <v-list-item-title>{{ metadataFieldLabel(change.field) }}</v-list-item-title>
              <v-list-item-subtitle class="metadata-change">
                <span>{{ metadataValue(change.current) }}</span>
                <v-icon icon="mdi-arrow-right" size="small" />
                <strong>{{ metadataValue(change.proposed) }}</strong>
              </v-list-item-subtitle>
            </v-list-item>
          </v-list>
          <v-alert v-else type="info" variant="tonal">{{ t('tracks.metadataNoChanges') }}</v-alert>
        </template>

        <template v-else-if="step === 'queued' && job">
          <div class="d-flex align-center ga-3">
            <v-progress-circular v-if="job.status !== 'failed'" color="primary" indeterminate size="28" />
            <div>
              <div class="font-weight-bold">{{ t(`tracks.metadataStatuses.${job.status}`) }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ t('tracks.metadataQueuedHint') }}</div>
            </div>
          </div>
          <v-alert v-if="job.backup" class="mt-4" type="info" variant="tonal">
            {{ t('settings.metadataBackupRecovery', { id: job.backup.id, path: job.backup.path }) }}
          </v-alert>
        </template>
      </v-card-text>

      <v-card-actions>
        <v-btn v-if="step === 'preview'" :disabled="loading" @click="step = 'form'">
          {{ t('tracks.metadataBack') }}
        </v-btn>
        <v-spacer />
        <v-btn
          :disabled="loading || (step === 'queued' && job?.status !== 'failed')"
          @click="isOpen = false"
        >
          {{ t('settings.cancel') }}
        </v-btn>
        <v-btn
          v-if="step === 'form'"
          color="primary"
          :disabled="!canEdit || !form.title.trim() || !cleanNames(form.artistNames).length"
          :loading="loading"
          variant="flat"
          @click="previewEdit"
        >
          {{ t('tracks.metadataReview') }}
        </v-btn>
        <v-btn
          v-else-if="step === 'preview'"
          color="primary"
          :disabled="!preview?.supported || !preview?.changes.length"
          :loading="loading"
          variant="flat"
          @click="queueEdit"
        >
          {{ t('tracks.metadataApply') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.metadata-change {
  align-items: center;
  display: flex;
  gap: 8px;
}
</style>

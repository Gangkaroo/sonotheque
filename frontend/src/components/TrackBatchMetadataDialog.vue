<script setup lang="ts">
import { computed, onUnmounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'
import type { Track } from '@/stores/catalog'

const props = defineProps<{
  albumId: number
  modelValue: boolean
  tracks: Track[]
}>()
const emit = defineEmits<{
  completed: [count: number]
  'update:modelValue': [value: boolean]
}>()

const { t } = useI18n()
const dialogOpen = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
})
const step = ref<'form' | 'preview' | 'queued'>('form')
const loading = ref(false)
const error = ref<string | null>(null)
const initial = ref<TrackBatchMetadataPreview | null>(null)
const preview = ref<TrackBatchMetadataPreview | null>(null)
const job = ref<MetadataJob | null>(null)
const enabled = reactive<Record<TrackBatchMetadataField, boolean>>({
  artistNames: false,
  composers: false,
  performers: false,
  genres: false,
  comment: false,
  trackNumber: false,
  discNumber: false,
  year: false,
})
const form = reactive({
  artistNames: [] as string[],
  composers: [] as string[],
  performers: [] as string[],
  genres: [] as string[],
  comment: '',
  trackNumber: '',
  discNumber: '',
  year: '',
})
let pollTimer: ReturnType<typeof setTimeout> | null = null

type TrackBatchMetadataField = 'artistNames' | 'composers' | 'performers' | 'genres' | 'comment' | 'trackNumber' | 'discNumber' | 'year'
type MetadataValue = string[] | string | number | null

interface TrackBatchFieldState {
  mixed: boolean
  value: MetadataValue
  values: MetadataValue[]
}

interface TrackBatchMetadataChange {
  field: TrackBatchMetadataField
  mixed: boolean
  current: MetadataValue
  proposed: MetadataValue
  affectedFiles: number
}

interface TrackBatchMetadataFile {
  trackId: number
  trackTitle: string
  file: string | null
  format: string | null
  supported: boolean
  supportIssue?: string | null
  affected: boolean
}

interface TrackBatchMetadataPreview {
  fingerprint: string
  fields: Record<TrackBatchMetadataField, TrackBatchFieldState>
  changes: TrackBatchMetadataChange[]
  files: TrackBatchMetadataFile[]
  affectedFiles: number
  unsupportedFiles: number
}

interface MetadataBackup {
  id: number
  path: string
}

interface MetadataJobItem {
  id: number
  trackId: number
  status: string
  file?: string | null
  trackTitle?: string | null
  error?: string | null
  backup?: MetadataBackup | null
}

interface MetadataJob {
  id: number
  status: 'pending' | 'running' | 'completed' | 'partial' | 'failed'
  totalItems: number
  processedItems: number
  succeededItems: number
  failedItems: number
  items: MetadataJobItem[]
  error?: string | null
  failureReason?: string | null
}

const hasChanges = computed(() => Object.values(enabled).some(Boolean))
const formValid = computed(() => {
  if (!hasChanges.value) return false
  return !enabled.artistNames || cleanNames(form.artistNames).length > 0
})

async function initialize() {
  step.value = 'form'
  loading.value = true
  error.value = null
  initial.value = null
  preview.value = null
  job.value = null
  Object.keys(enabled).forEach((field) => {
    enabled[field as TrackBatchMetadataField] = false
  })

  try {
    const result = await requestPreview({})
    initial.value = result
    populateForm(result)
  } catch (cause) {
    error.value = errorMessage(cause)
  } finally {
    loading.value = false
  }
}

function populateForm(result: TrackBatchMetadataPreview) {
  const current = (field: TrackBatchMetadataField) => result.fields[field].value
  const names = (field: TrackBatchMetadataField) => {
    const value = current(field)
    return Array.isArray(value) ? [...value] : []
  }
  const text = (field: TrackBatchMetadataField) => {
    const value = current(field)
    return typeof value === 'string' || typeof value === 'number' ? String(value) : ''
  }

  Object.assign(form, {
    artistNames: names('artistNames'),
    composers: names('composers'),
    performers: names('performers'),
    genres: names('genres'),
    comment: text('comment'),
    trackNumber: text('trackNumber'),
    discNumber: text('discNumber'),
    year: text('year'),
  })
}

function cleanNames(names: string[]) {
  return names.map((name) => name.trim()).filter(Boolean)
}

function nullableInteger(value: string) {
  const trimmed = value.trim()
  return trimmed === '' ? null : Number(trimmed)
}

function changes() {
  const values: Partial<Record<TrackBatchMetadataField, MetadataValue>> = {}
  if (enabled.artistNames) values.artistNames = cleanNames(form.artistNames)
  if (enabled.composers) values.composers = cleanNames(form.composers)
  if (enabled.performers) values.performers = cleanNames(form.performers)
  if (enabled.genres) values.genres = cleanNames(form.genres)
  if (enabled.comment) values.comment = form.comment.trim() || null
  if (enabled.trackNumber) values.trackNumber = nullableInteger(form.trackNumber)
  if (enabled.discNumber) values.discNumber = nullableInteger(form.discNumber)
  if (enabled.year) values.year = nullableInteger(form.year)

  return values
}

function requestPreview(values: Partial<Record<TrackBatchMetadataField, MetadataValue>>) {
  return apiRequest<TrackBatchMetadataPreview>(`/albums/${props.albumId}/tracks/metadata/preview`, {
    method: 'POST',
    body: JSON.stringify({ trackIds: props.tracks.map((track) => track.id), changes: values }),
  })
}

async function review() {
  loading.value = true
  error.value = null
  try {
    preview.value = await requestPreview(changes())
    step.value = 'preview'
  } catch (cause) {
    error.value = errorMessage(cause)
  } finally {
    loading.value = false
  }
}

async function apply() {
  if (!preview.value) return

  loading.value = true
  error.value = null
  try {
    job.value = await apiRequest<MetadataJob>(`/albums/${props.albumId}/tracks/metadata-edits`, {
      method: 'POST',
      body: JSON.stringify({
        trackIds: props.tracks.map((track) => track.id),
        changes: changes(),
        fingerprint: preview.value.fingerprint,
      }),
    })
    step.value = 'queued'
    schedulePoll()
  } catch (cause) {
    error.value = errorMessage(cause)
  } finally {
    loading.value = false
  }
}

function schedulePoll() {
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = setTimeout(poll, 1000)
}

async function poll() {
  if (!job.value) return

  try {
    job.value = await apiRequest<MetadataJob>(`/metadata-edits/${job.value.id}`)
    if (job.value.status === 'completed') {
      dialogOpen.value = false
      emit('completed', job.value.succeededItems)
      return
    }
    if (['partial', 'failed'].includes(job.value.status)) return
    schedulePoll()
  } catch (cause) {
    error.value = errorMessage(cause)
  }
}

function fieldLabel(field: TrackBatchMetadataField) {
  return {
    artistNames: t('tracks.artists'),
    composers: t('tracks.composers'),
    performers: t('tracks.performers'),
    genres: t('tracks.genres'),
    comment: t('tracks.comment'),
    trackNumber: t('tracks.trackNumber'),
    discNumber: t('tracks.discNumber'),
    year: t('tracks.year'),
  }[field]
}

function valueLabel(value: MetadataValue) {
  if (Array.isArray(value)) return value.length ? value.join(', ') : '-'
  return value === null || value === '' ? '-' : String(value)
}

function currentHint(field: TrackBatchMetadataField) {
  const state = initial.value?.fields[field]
  if (!state) return ''
  if (!state.mixed) return t('albums.currentValue', { value: valueLabel(state.value) })

  return t('albums.multipleCurrentValues', { values: state.values.map(valueLabel).join(' · ') })
}

function progress() {
  if (!job.value?.totalItems) return 0
  return Math.round((job.value.processedItems / job.value.totalItems) * 100)
}

function errorMessage(cause: unknown) {
  return cause instanceof Error ? cause.message : t('albums.metadataEditFailed')
}

watch(dialogOpen, (open) => {
  if (open) void initialize()
})

onUnmounted(() => {
  if (pollTimer) clearTimeout(pollTimer)
})
</script>

<template>
  <v-dialog v-model="dialogOpen" max-width="760" persistent scrollable>
    <v-card prepend-icon="mdi-tag-multiple-outline" :title="t('albums.batchMetadataTitle', { count: tracks.length })">
      <v-card-text>
        <v-alert v-if="error" class="mb-4" type="error" variant="tonal">{{ error }}</v-alert>

        <template v-if="step === 'form'">
          <v-alert class="mb-4" type="info" variant="tonal">{{ t('albums.batchMetadataHint') }}</v-alert>
          <v-skeleton-loader v-if="loading && !initial" type="list-item-two-line@6" />
          <template v-else-if="initial">
            <div class="metadata-field">
              <v-checkbox v-model="enabled.artistNames" :aria-label="t('albums.changeField')" density="compact" hide-details />
              <v-combobox v-model="form.artistNames" chips closable-chips :disabled="!enabled.artistNames" :hint="currentHint('artistNames')" :label="t('tracks.artists')" multiple persistent-hint />
            </div>
            <div class="metadata-field">
              <v-checkbox v-model="enabled.composers" :aria-label="t('albums.changeField')" density="compact" hide-details />
              <v-combobox v-model="form.composers" chips clearable closable-chips :disabled="!enabled.composers" :hint="currentHint('composers')" :label="t('tracks.composers')" multiple persistent-hint />
            </div>
            <div class="metadata-field">
              <v-checkbox v-model="enabled.performers" :aria-label="t('albums.changeField')" density="compact" hide-details />
              <v-combobox v-model="form.performers" chips clearable closable-chips :disabled="!enabled.performers" :hint="currentHint('performers')" :label="t('tracks.performers')" multiple persistent-hint />
            </div>
            <div class="metadata-field">
              <v-checkbox v-model="enabled.genres" :aria-label="t('albums.changeField')" density="compact" hide-details />
              <v-combobox v-model="form.genres" chips clearable closable-chips :disabled="!enabled.genres" :hint="currentHint('genres')" :label="t('tracks.genres')" multiple persistent-hint />
            </div>
            <div class="metadata-field">
              <v-checkbox v-model="enabled.comment" :aria-label="t('albums.changeField')" density="compact" hide-details />
              <v-textarea v-model="form.comment" clearable :disabled="!enabled.comment" :hint="currentHint('comment')" :label="t('tracks.comment')" persistent-hint rows="3" />
            </div>
            <div class="metadata-numbers">
              <div class="metadata-field">
                <v-checkbox v-model="enabled.trackNumber" :aria-label="t('albums.changeField')" density="compact" hide-details />
                <v-text-field v-model="form.trackNumber" clearable :disabled="!enabled.trackNumber" :hint="currentHint('trackNumber')" inputmode="numeric" :label="t('tracks.trackNumber')" persistent-hint />
              </div>
              <div class="metadata-field">
                <v-checkbox v-model="enabled.discNumber" :aria-label="t('albums.changeField')" density="compact" hide-details />
                <v-text-field v-model="form.discNumber" clearable :disabled="!enabled.discNumber" :hint="currentHint('discNumber')" inputmode="numeric" :label="t('tracks.discNumber')" persistent-hint />
              </div>
              <div class="metadata-field">
                <v-checkbox v-model="enabled.year" :aria-label="t('albums.changeField')" density="compact" hide-details />
                <v-text-field v-model="form.year" clearable :disabled="!enabled.year" :hint="currentHint('year')" inputmode="numeric" :label="t('tracks.year')" persistent-hint />
              </div>
            </div>
          </template>
        </template>

        <template v-else-if="step === 'preview' && preview">
          <v-alert v-if="preview.unsupportedFiles" class="mb-4" type="warning" variant="tonal">
            {{ t('albums.batchMetadataUnsupported', { count: preview.unsupportedFiles }) }}
          </v-alert>
          <v-list v-if="preview.changes.length" border rounded class="mb-4">
            <v-list-item v-for="change in preview.changes" :key="change.field">
              <v-list-item-title>{{ fieldLabel(change.field) }}</v-list-item-title>
              <v-list-item-subtitle class="metadata-change">
                <span>{{ change.mixed ? t('albums.multipleValues') : valueLabel(change.current) }}</span>
                <v-icon icon="mdi-arrow-right" size="small" />
                <strong>{{ valueLabel(change.proposed) }}</strong>
              </v-list-item-subtitle>
              <template #append>
                <v-chip size="x-small" variant="tonal">{{ t('albums.affectedTrackCount', { count: change.affectedFiles }) }}</v-chip>
              </template>
            </v-list-item>
          </v-list>
          <v-alert v-else class="mb-4" type="info" variant="tonal">{{ t('albums.metadataNoChanges') }}</v-alert>

          <div class="text-subtitle-2 mb-2">{{ t('albums.metadataAffectedFiles', { count: preview.affectedFiles }) }}</div>
          <v-list border rounded class="metadata-file-list" density="compact">
            <v-list-item
              v-for="file in preview.files.filter((entry) => entry.affected)"
              :key="file.trackId"
              :prepend-icon="file.supported ? 'mdi-file-music-outline' : 'mdi-alert-outline'"
              :title="file.trackTitle"
            >
              <v-list-item-subtitle>
                <div>{{ file.file ?? '-' }}</div>
                <div v-if="file.supportIssue" class="text-warning">{{ t(`tracks.metadataSupportIssues.${file.supportIssue}`) }}</div>
              </v-list-item-subtitle>
            </v-list-item>
          </v-list>
        </template>

        <template v-else-if="step === 'queued' && job">
          <div class="font-weight-bold mb-2">{{ t(`albums.metadataStatuses.${job.status}`) }}</div>
          <v-progress-linear class="mb-2" color="primary" :model-value="progress()" rounded />
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('albums.metadataProgress', { processed: job.processedItems, total: job.totalItems, failed: job.failedItems }) }}
          </div>
          <v-alert
            v-if="['partial', 'failed'].includes(job.status)"
            class="mb-4"
            type="error"
            variant="tonal"
          >
            {{ job.failureReason ?? job.error ?? t('albums.metadataEditFailed') }}
          </v-alert>
          <v-list v-if="job.items.some((item) => item.status === 'failed')" border rounded class="metadata-file-list" density="compact">
            <v-list-item
              v-for="item in job.items.filter((entry) => entry.status === 'failed')"
              :key="item.id"
              prepend-icon="mdi-alert-circle-outline"
              :title="item.trackTitle ?? item.file ?? String(item.trackId)"
            >
              <v-list-item-subtitle>{{ item.error ?? t('albums.metadataEditFailed') }}</v-list-item-subtitle>
              <v-list-item-subtitle v-if="item.backup">{{ t('settings.metadataBackupRecovery', { id: item.backup.id, path: item.backup.path }) }}</v-list-item-subtitle>
            </v-list-item>
          </v-list>
        </template>
      </v-card-text>
      <v-card-actions>
        <v-btn v-if="step === 'preview'" :disabled="loading" @click="step = 'form'">{{ t('tracks.metadataBack') }}</v-btn>
        <v-spacer />
        <v-btn :disabled="loading || (step === 'queued' && !['partial', 'failed'].includes(job?.status ?? ''))" @click="dialogOpen = false">
          {{ t('settings.cancel') }}
        </v-btn>
        <v-btn v-if="step === 'form'" color="primary" :disabled="!initial || !formValid" :loading="loading" variant="flat" @click="review">
          {{ t('tracks.metadataReview') }}
        </v-btn>
        <v-btn v-else-if="step === 'preview'" color="primary" :disabled="!preview?.affectedFiles || Boolean(preview?.unsupportedFiles)" :loading="loading" variant="flat" @click="apply">
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

.metadata-file-list {
  max-height: 320px;
  overflow-y: auto;
}

.metadata-field {
  align-items: start;
  display: grid;
  gap: 12px;
  grid-template-columns: 2.5rem minmax(0, 1fr);
  margin-bottom: 8px;
}

.metadata-numbers {
  display: grid;
  gap: 8px;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
}

.metadata-numbers .metadata-field {
  grid-template-columns: 2.5rem minmax(7rem, 1fr);
}

@media (max-width: 480px) {
  .metadata-field,
  .metadata-numbers .metadata-field {
    gap: 2px;
    grid-template-columns: 1fr;
  }
}
</style>

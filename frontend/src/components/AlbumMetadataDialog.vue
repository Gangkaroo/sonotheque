<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'
import AdditionalMetadataTagRemovalList from '@/components/AdditionalMetadataTagRemovalList.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { useBackgroundJobPolling } from '@/composables/useBackgroundJobPolling'
import type { AlbumDetail } from '@/stores/catalog'

const props = defineProps<{
  modelValue: boolean
  album: AlbumDetail
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  completed: []
  releaseResolved: []
}>()

const { t } = useI18n()
const step = ref<'form' | 'preview' | 'queued'>('form')
const loading = ref(false)
const error = ref<string | null>(null)
const preview = ref<AlbumMetadataPreview | null>(null)
const commentEnabled = ref(false)
const commentsMixed = ref(false)
const updateTrackArtists = ref(true)
const removedTagKeys = ref<string[]>([])
const recordLabelProvenance = ref<RecordLabelProvenance[]>([])
const recordLabelSuggestions = ref<RecordLabelSuggestion[]>([])
const recordLabelSuggestionsLoading = ref(false)
const recordLabelSuggestionsLoaded = ref(false)
const recordLabelSuggestionsError = ref<string | null>(null)
const recordLabelSuggestionConfirming = ref<string | null>(null)
const form = reactive({
  albumTitle: '',
  albumArtist: '',
  releaseYear: '',
  totalDiscs: '',
  genres: [] as string[],
  recordLabels: [] as AlbumMetadataRecordLabel[],
  comment: '',
})

interface AlbumMetadataRecordLabel {
  name: string
  catalogNumber?: string | null
}

interface RecordLabelProvenance extends AlbumMetadataRecordLabel {
  source: 'discogs' | 'musicbrainz'
  sourceReference: string
}

interface RecordLabelSuggestion {
  provider: 'discogs' | 'musicbrainz'
  providerLabel: string
  sourceReference: string
  sourceUrl?: string | null
  sourceDescription?: string | null
  sourceTrackCount?: number | null
  recordLabels: AlbumMetadataRecordLabel[]
  matchesCurrent: boolean
  sourceConfirmed: boolean
}

interface RecordLabelSuggestionPayload {
  suggestions: RecordLabelSuggestion[]
  errors: Array<{ provider: string, message: string }>
  musicianReleaseResolved?: boolean
}

interface AlbumMetadataValues {
  albumTitle: string
  albumArtist: string
  updateTrackArtists: boolean
  releaseYear: number | null
  totalDiscs: number | null
  genres: string[]
  recordLabels: AlbumMetadataRecordLabel[]
  recordLabelProvenance?: RecordLabelProvenance[]
  comment?: string | null
  removedTagKeys: string[]
}

interface AlbumMetadataChange {
  field: 'albumTitle' | 'albumArtist' | 'releaseYear' | 'totalDiscs' | 'genres' | 'recordLabels' | 'comment' | 'removedTagKeys'
  current: string | number | string[] | AlbumMetadataRecordLabel[] | null
  proposed: string | number | string[] | AlbumMetadataRecordLabel[] | null
  fileValuesDiffer?: boolean
}

interface AlbumMetadataFile {
  trackId: number
  trackTitle: string
  file: string | null
  format: string | null
  supported: boolean
  supportIssue?: string | null
}

interface AlbumMetadataPreview {
  albumId: number
  fingerprint: string
  values: AlbumMetadataValues
  changes: AlbumMetadataChange[]
  trackArtistsWillChange: boolean
  files: AlbumMetadataFile[]
  supportedFiles: number
  unsupportedFiles: number
}

interface MetadataBackup {
  id: number
  path: string
  expiresAt: string | null
  restoredAt: string | null
  deletedAt: string | null
}

interface AlbumMetadataJobItem {
  id: number
  trackId: number
  status: string
  file?: string | null
  trackTitle?: string | null
  error?: string | null
  backup?: MetadataBackup | null
}

interface AlbumMetadataJob {
  id: number
  status: 'pending' | 'running' | 'completed' | 'partial' | 'failed'
  totalItems: number
  processedItems: number
  succeededItems: number
  failedItems: number
  items: AlbumMetadataJobItem[]
  error?: string | null
  failureReason?: string | null
}

const isOpen = computed({
  get: () => props.modelValue,
  set: value => emit('update:modelValue', value),
})

const {
  job,
  start: startPolling,
  reset: resetPolling,
} = useBackgroundJobPolling<AlbumMetadataJob>({
  request: (id, signal) => apiRequest<AlbumMetadataJob>(`/metadata-edits/${id}`, { signal }),
  isComplete: value => value.status === 'completed',
  isTerminal: value => ['partial', 'failed'].includes(value.status),
  onComplete: () => {
    isOpen.value = false
    emit('completed')
  },
  onError: cause => {
    error.value = cause instanceof Error ? cause.message : t('albums.metadataEditFailed')
  },
})

function initialize() {
  resetPolling()
  const comments = [...new Set(props.album.tracks.map(track => track.comment ?? null))]
  Object.assign(form, {
    albumTitle: props.album.title,
    albumArtist: props.album.primaryArtist?.name ?? '',
    releaseYear: props.album.originalReleaseYear?.toString() ?? '',
    totalDiscs: props.album.discTotal?.toString() ?? '',
    genres: props.album.genres.map(genre => genre.name),
    recordLabels: props.album.recordLabels.map(recordLabel => ({
      name: recordLabel.name,
      catalogNumber: recordLabel.catalogNumber ?? null,
    })),
    comment: comments.length === 1 ? comments[0] ?? '' : '',
  })
  commentEnabled.value = false
  commentsMixed.value = comments.length > 1
  updateTrackArtists.value = true
  removedTagKeys.value = []
  recordLabelProvenance.value = []
  recordLabelSuggestions.value = []
  recordLabelSuggestionsLoaded.value = false
  recordLabelSuggestionsError.value = null
  step.value = 'form'
  preview.value = null
  error.value = null
  void loadRecordLabelSuggestions()
}

function metadataValues(): AlbumMetadataValues {
  const year = form.releaseYear.trim()
  const totalDiscs = form.totalDiscs.trim()
  const values: AlbumMetadataValues = {
    albumTitle: form.albumTitle.trim(),
    albumArtist: form.albumArtist.trim(),
    updateTrackArtists: updateTrackArtists.value,
    releaseYear: year === '' ? null : Number(year),
    totalDiscs: totalDiscs === '' ? null : Number(totalDiscs),
    genres: form.genres.map(genre => genre.trim()).filter(Boolean),
    recordLabels: form.recordLabels
      .map(recordLabel => ({
        name: recordLabel.name.trim(),
        catalogNumber: recordLabel.catalogNumber?.trim() || null,
      }))
      .filter(recordLabel => recordLabel.name !== ''),
    removedTagKeys: [...removedTagKeys.value],
  }
  const identities = new Set(values.recordLabels.map(recordLabelIdentity))
  const provenance = recordLabelProvenance.value.filter(label => identities.has(recordLabelIdentity(label)))
  if (provenance.length) values.recordLabelProvenance = provenance
  if (commentEnabled.value) values.comment = form.comment.trim() || null

  return values
}

function recordLabelIdentity(recordLabel: AlbumMetadataRecordLabel) {
  return `${recordLabel.name.trim().toLocaleLowerCase()}|${recordLabel.catalogNumber?.trim().toLocaleLowerCase() ?? ''}`
}

function recordLabelText(recordLabel: AlbumMetadataRecordLabel) {
  return recordLabel.catalogNumber ? `${recordLabel.name} · ${recordLabel.catalogNumber}` : recordLabel.name
}

function suggestionKey(suggestion: RecordLabelSuggestion) {
  return `${suggestion.provider}:${suggestion.sourceReference}`
}

async function loadRecordLabelSuggestions() {
  recordLabelSuggestionsLoading.value = true
  recordLabelSuggestionsError.value = null
  try {
    const result = await apiRequest<RecordLabelSuggestionPayload>(
      `/albums/${props.album.id}/record-label-suggestions`,
    )
    applySuggestionResult(result)
  } catch (cause) {
    recordLabelSuggestionsError.value = cause instanceof Error
      ? cause.message
      : t('albums.recordLabelSuggestionsFailed')
  } finally {
    recordLabelSuggestionsLoading.value = false
    recordLabelSuggestionsLoaded.value = true
  }
}

function applySuggestionResult(result: RecordLabelSuggestionPayload, notifyReleaseResolution = false) {
  recordLabelSuggestions.value = result.suggestions
  recordLabelSuggestionsError.value = result.errors.length
    ? result.errors.map(item => item.message).join(' ')
    : null
  if (notifyReleaseResolution && result.musicianReleaseResolved) emit('releaseResolved')
}

async function useSuggestion(suggestion: RecordLabelSuggestion) {
  form.recordLabels = suggestion.recordLabels.map(recordLabel => ({ ...recordLabel }))
  recordLabelProvenance.value = suggestion.recordLabels.map(recordLabel => ({
    ...recordLabel,
    source: suggestion.provider,
    sourceReference: suggestion.sourceReference,
  }))
  await selectSuggestion(suggestion)
}

async function selectSuggestion(suggestion: RecordLabelSuggestion) {
  await submitSuggestion(suggestion, 'select', 'albums.recordLabelSuggestionsFailed')
}

async function confirmSuggestion(suggestion: RecordLabelSuggestion) {
  if (!suggestion.matchesCurrent || suggestion.sourceConfirmed) return
  await submitSuggestion(suggestion, 'confirm', 'albums.recordLabelSourceConfirmationFailed')
}

async function submitSuggestion(
  suggestion: RecordLabelSuggestion,
  action: 'select' | 'confirm',
  fallbackMessage: string,
) {
  const key = suggestionKey(suggestion)
  recordLabelSuggestionConfirming.value = key
  recordLabelSuggestionsError.value = null
  try {
    const result = await apiRequest<RecordLabelSuggestionPayload>(
      `/albums/${props.album.id}/record-label-suggestions/${action}`,
      {
        method: 'POST',
        body: JSON.stringify({
          provider: suggestion.provider,
          sourceReference: suggestion.sourceReference,
        }),
      },
    )
    applySuggestionResult(result, true)
  } catch (cause) {
    recordLabelSuggestionsError.value = cause instanceof Error ? cause.message : t(fallbackMessage)
  } finally {
    recordLabelSuggestionConfirming.value = null
  }
}

async function previewEdit() {
  loading.value = true
  error.value = null
  try {
    preview.value = await apiRequest<AlbumMetadataPreview>(`/albums/${props.album.id}/metadata/preview`, {
      method: 'POST',
      body: JSON.stringify(metadataValues()),
    })
    step.value = 'preview'
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('albums.metadataEditFailed')
  } finally {
    loading.value = false
  }
}

async function queueEdit() {
  if (!preview.value) return
  loading.value = true
  error.value = null
  try {
    const queuedJob = await apiRequest<AlbumMetadataJob>(`/albums/${props.album.id}/metadata-edits`, {
      method: 'POST',
      body: JSON.stringify({ ...preview.value.values, fingerprint: preview.value.fingerprint }),
    })
    step.value = 'queued'
    startPolling(queuedJob)
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('albums.metadataEditFailed')
  } finally {
    loading.value = false
  }
}

function metadataFieldLabel(field: AlbumMetadataChange['field']) {
  return t({
    albumTitle: 'albums.metadataAlbumTitle',
    albumArtist: 'albums.metadataAlbumArtist',
    releaseYear: 'albums.releaseYear',
    totalDiscs: 'albums.totalDiscs',
    genres: 'tracks.genres',
    recordLabels: 'albums.recordLabels',
    comment: 'tracks.comment',
    removedTagKeys: 'tracks.removeAdditionalTags',
  }[field])
}

function metadataValue(value: AlbumMetadataChange['current']) {
  if (Array.isArray(value)) {
    if (!value.length) return '-'
    return value.map(item => typeof item === 'string' ? item : recordLabelText(item)).join(', ')
  }
  return value === null || value === '' ? '-' : String(value)
}

function metadataProgress() {
  return job.value?.totalItems
    ? Math.round((job.value.processedItems / job.value.totalItems) * 100)
    : 0
}

function unsupportedSummary() {
  const unsupportedFiles = preview.value?.files.filter(file => !file.supported) ?? []
  const issues = unsupportedFiles.map(file => file.supportIssue)
  const count = unsupportedFiles.length
  if (issues.every(issue => issue?.startsWith('id3v2_'))) return t('albums.metadataUnsupportedTagFiles', { count })
  if (issues.every(issue => issue === 'unsupported_format')) return t('albums.metadataUnsupportedFiles', { count })
  return t('albums.metadataUnsupportedMixedFiles', { count })
}

watch(() => props.modelValue, (open) => {
  if (open) initialize()
}, { immediate: true })

watch(() => props.album.id, () => {
  resetPolling()
  if (props.modelValue) initialize()
})
</script>

<template>
  <v-dialog v-model="isOpen" max-width="760" persistent scrollable>
    <v-card prepend-icon="mdi-tag-edit-outline" :title="t('albums.editMetadata')">
      <v-card-text>
        <v-alert v-if="error" class="mb-4" type="error" variant="tonal">{{ error }}</v-alert>

        <template v-if="step === 'form'">
          <v-text-field v-model="form.albumTitle" :label="t('albums.metadataAlbumTitle')" maxlength="512" />
          <v-text-field v-model="form.albumArtist" :label="t('albums.metadataAlbumArtist')" maxlength="512" />
          <v-switch
            v-model="updateTrackArtists"
            class="mt-n2"
            color="primary"
            density="compact"
            hide-details
            :label="t('albums.metadataUpdateTrackArtists')"
          />
          <div class="text-caption text-medium-emphasis mb-3">
            {{ t('albums.metadataUpdateTrackArtistsHint') }}
          </div>
          <v-text-field v-model="form.releaseYear" inputmode="numeric" :label="t('albums.releaseYear')" />
          <v-text-field v-model="form.totalDiscs" inputmode="numeric" :label="t('albums.totalDiscs')" />
          <v-combobox
            v-model="form.genres"
            chips
            closable-chips
            clearable
            :hint="t('albums.metadataGenresHint')"
            :label="t('tracks.genres')"
            multiple
            persistent-hint
          />

          <div class="d-flex align-center justify-space-between mb-2">
            <div>
              <div class="text-subtitle-2">{{ t('albums.recordLabels') }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('albums.metadataRecordLabelsHint') }}</div>
            </div>
            <v-btn
              :disabled="form.recordLabels.length >= 20"
              prepend-icon="mdi-plus"
              size="small"
              variant="text"
              @click="form.recordLabels.push({ name: '', catalogNumber: null })"
            >
              {{ t('albums.addRecordLabel') }}
            </v-btn>
          </div>
          <div v-if="!form.recordLabels.length" class="text-caption text-medium-emphasis mb-4">
            {{ t('albums.noRecordLabels') }}
          </div>
          <v-row
            v-for="(recordLabel, index) in form.recordLabels"
            :key="index"
            align="center"
            class="metadata-record-label-row"
            density="comfortable"
          >
            <v-col cols="12" sm="7">
              <v-text-field
                v-model="recordLabel.name"
                density="compact"
                hide-details
                :label="t('albums.recordLabel')"
                maxlength="255"
              />
            </v-col>
            <v-col cols="10" sm="4">
              <v-text-field
                v-model="recordLabel.catalogNumber"
                density="compact"
                hide-details
                :label="t('albums.catalogNumber')"
                maxlength="128"
              />
            </v-col>
            <v-col class="d-flex justify-end" cols="2" sm="1">
              <TooltipIconButton
                color="error"
                icon="mdi-delete-outline"
                size="small"
                :text="t('albums.removeRecordLabel')"
                variant="text"
                @click="form.recordLabels.splice(index, 1)"
              />
            </v-col>
          </v-row>

          <div class="record-label-suggestions mb-4">
            <div class="d-flex align-center justify-space-between mb-2">
              <div class="text-subtitle-2">{{ t('albums.recordLabelSuggestions') }}</div>
              <v-btn
                v-if="recordLabelSuggestionsLoaded"
                :disabled="recordLabelSuggestionsLoading"
                icon="mdi-refresh"
                size="x-small"
                :title="t('albums.refreshRecordLabelSuggestions')"
                variant="text"
                @click="loadRecordLabelSuggestions"
              />
            </div>
            <v-progress-linear v-if="recordLabelSuggestionsLoading" color="primary" indeterminate rounded />
            <v-alert v-if="recordLabelSuggestionsError" class="mb-2" density="compact" type="warning" variant="tonal">
              {{ recordLabelSuggestionsError }}
            </v-alert>
            <div v-if="recordLabelSuggestions.length" class="d-flex flex-column ga-2">
              <v-card
                v-for="suggestion in recordLabelSuggestions"
                :key="suggestionKey(suggestion)"
                border
                density="compact"
                variant="tonal"
              >
                <v-card-text class="record-label-suggestion">
                  <div class="min-width-0">
                    <div class="d-flex flex-wrap align-center ga-2">
                      <strong>{{ suggestion.providerLabel }}</strong>
                      <v-chip
                        :color="suggestion.matchesCurrent ? 'success' : 'primary'"
                        size="x-small"
                        variant="tonal"
                      >
                        {{ t(suggestion.matchesCurrent
                          ? 'albums.recordLabelSuggestionMatches'
                          : 'albums.recordLabelSuggestionDiffers') }}
                      </v-chip>
                    </div>
                    <div class="text-body-2 text-medium-emphasis mt-1">
                      {{ suggestion.recordLabels.map(recordLabelText).join(', ') }}
                    </div>
                    <div
                      v-if="suggestion.sourceDescription || suggestion.sourceTrackCount != null"
                      class="text-caption text-medium-emphasis"
                    >
                      <span v-if="suggestion.sourceDescription">{{ suggestion.sourceDescription }}</span>
                      <span v-if="suggestion.sourceTrackCount != null">
                        <span v-if="suggestion.sourceDescription"> · </span>{{ t('albums.trackCount', { count: suggestion.sourceTrackCount }) }}
                      </span>
                    </div>
                    <a
                      v-if="suggestion.sourceUrl"
                      class="text-caption"
                      :href="suggestion.sourceUrl"
                      rel="noopener noreferrer"
                      target="_blank"
                    >
                      {{ t('albums.openRecordLabelSource') }}
                    </a>
                  </div>
                  <v-btn
                    color="primary"
                    :disabled="suggestion.sourceConfirmed"
                    :loading="recordLabelSuggestionConfirming === suggestionKey(suggestion)"
                    size="small"
                    variant="tonal"
                    @click="suggestion.matchesCurrent ? confirmSuggestion(suggestion) : useSuggestion(suggestion)"
                  >
                    {{ suggestion.matchesCurrent
                      ? t(suggestion.sourceConfirmed
                        ? 'albums.recordLabelSourceConfirmed'
                        : 'albums.confirmRecordLabelSource')
                      : t('albums.useRecordLabelSuggestion') }}
                  </v-btn>
                </v-card-text>
              </v-card>
            </div>
            <div
              v-else-if="recordLabelSuggestionsLoaded && !recordLabelSuggestionsLoading && !recordLabelSuggestionsError"
              class="text-caption text-medium-emphasis"
            >
              {{ t('albums.noRecordLabelSuggestions') }}
            </div>
          </div>

          <v-switch
            v-model="commentEnabled"
            color="primary"
            density="compact"
            hide-details
            :label="t('albums.metadataChangeComment')"
          />
          <v-textarea
            v-model="form.comment"
            clearable
            :disabled="!commentEnabled"
            :hint="commentsMixed ? t('albums.metadataCommentMixedHint') : t('albums.metadataCommentHint')"
            :label="t('tracks.comment')"
            maxlength="10000"
            persistent-hint
            rows="3"
          />
          <template v-if="album.additionalTags.length">
            <v-divider class="mb-4" />
            <AdditionalMetadataTagRemovalList
              v-model="removedTagKeys"
              :hint="t('albums.removeAdditionalTagsHint')"
              :tags="album.additionalTags"
              :title="t('tracks.removeAdditionalTags')"
              :total-track-count="album.trackCount"
            />
          </template>
          <div class="text-caption text-medium-emphasis">{{ t('albums.metadataPreviewHint') }}</div>
        </template>

        <template v-else-if="step === 'preview' && preview">
          <v-alert v-if="preview.trackArtistsWillChange" class="mb-4" type="info" variant="tonal">
            {{ t('albums.metadataTrackArtistsWillChange') }}
          </v-alert>
          <v-alert v-if="preview.unsupportedFiles" class="mb-4" type="warning" variant="tonal">
            {{ unsupportedSummary() }}
          </v-alert>
          <v-list v-if="preview.changes.length" border rounded class="mb-4">
            <v-list-item v-for="change in preview.changes" :key="change.field">
              <v-list-item-title>{{ metadataFieldLabel(change.field) }}</v-list-item-title>
              <v-list-item-subtitle class="metadata-change">
                <span>{{ metadataValue(change.current) }}</span>
                <v-icon icon="mdi-arrow-right" size="small" />
                <strong>{{ metadataValue(change.proposed) }}</strong>
              </v-list-item-subtitle>
              <v-list-item-subtitle v-if="change.fileValuesDiffer" class="text-medium-emphasis">
                {{ t('albums.metadataFileValuesDiffer') }}
              </v-list-item-subtitle>
            </v-list-item>
          </v-list>
          <v-alert v-else class="mb-4" type="info" variant="tonal">{{ t('albums.metadataNoChanges') }}</v-alert>

          <div class="text-subtitle-2 mb-2">{{ t('albums.metadataAffectedFiles', { count: preview.files.length }) }}</div>
          <v-list border rounded class="metadata-file-list" density="compact">
            <v-list-item
              v-for="file in preview.files"
              :key="file.trackId"
              :prepend-icon="file.supported ? 'mdi-file-music-outline' : 'mdi-alert-outline'"
              :title="file.trackTitle"
            >
              <v-list-item-subtitle>
                <div>{{ file.file ?? '-' }}</div>
                <div v-if="file.supportIssue" class="text-warning">
                  {{ t(`tracks.metadataSupportIssues.${file.supportIssue}`) }}
                </div>
              </v-list-item-subtitle>
              <template #append>
                <v-chip :color="file.supported ? 'success' : 'warning'" size="x-small" variant="tonal">
                  {{ file.format ?? '-' }}
                </v-chip>
              </template>
            </v-list-item>
          </v-list>
        </template>

        <template v-else-if="step === 'queued' && job">
          <div class="font-weight-bold mb-2">{{ t(`albums.metadataStatuses.${job.status}`) }}</div>
          <v-progress-linear class="mb-2" color="primary" :model-value="metadataProgress()" rounded />
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('albums.metadataProgress', {
              processed: job.processedItems,
              total: job.totalItems,
              failed: job.failedItems,
            }) }}
          </div>
          <v-alert
            v-if="['partial', 'failed'].includes(job.status)"
            class="mb-4"
            type="error"
            variant="tonal"
          >
            {{ job.failureReason ?? job.error ?? t('albums.metadataEditFailed') }}
          </v-alert>
          <v-list v-if="job.items.some(item => item.status === 'failed')" border rounded class="metadata-file-list" density="compact">
            <v-list-item
              v-for="item in job.items.filter(entry => entry.status === 'failed')"
              :key="item.id"
              prepend-icon="mdi-alert-circle-outline"
              :title="item.trackTitle ?? item.file ?? String(item.trackId)"
            >
              <v-list-item-subtitle>{{ item.error ?? t('albums.metadataEditFailed') }}</v-list-item-subtitle>
              <v-list-item-subtitle v-if="item.backup">
                {{ t('settings.metadataBackupRecovery', { id: item.backup.id, path: item.backup.path }) }}
              </v-list-item-subtitle>
            </v-list-item>
          </v-list>
        </template>
      </v-card-text>

      <v-card-actions>
        <v-btn v-if="step === 'preview'" :disabled="loading" @click="step = 'form'">
          {{ t('tracks.metadataBack') }}
        </v-btn>
        <v-spacer />
        <v-btn
          :disabled="loading || (step === 'queued' && !['partial', 'failed'].includes(job?.status ?? ''))"
          @click="isOpen = false"
        >
          {{ t('settings.cancel') }}
        </v-btn>
        <v-btn
          v-if="step === 'form'"
          color="primary"
          :disabled="!form.albumTitle.trim() || !form.albumArtist.trim()"
          :loading="loading"
          variant="flat"
          @click="previewEdit"
        >
          {{ t('tracks.metadataReview') }}
        </v-btn>
        <v-btn
          v-else-if="step === 'preview'"
          color="primary"
          :disabled="!preview?.changes.length || Boolean(preview?.unsupportedFiles)"
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

.metadata-file-list {
  max-height: 320px;
  overflow-y: auto;
}

.record-label-suggestion {
  align-items: center;
  display: flex;
  gap: 12px;
  justify-content: space-between;
}

@media (max-width: 599px) {
  .record-label-suggestion {
    align-items: stretch;
    flex-direction: column;
  }
}
</style>

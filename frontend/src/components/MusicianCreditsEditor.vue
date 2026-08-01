<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'
import { openExternalUrl } from '@/utils/externalLinks'

import TooltipIconButton from './TooltipIconButton.vue'

interface MusicianSummary {
  id: number
  name: string
  disambiguation?: string | null
}

interface CreditTrack {
  id: number
  title: string
  discNumber?: number | null
  trackNumber?: number | null
}

interface CreditItem {
  id: number | null
  sourceKey: string | null
  provider: string
  manual: boolean
  hidden: boolean
  musician: MusicianSummary
  role: string
  creditedAs?: string | null
  guest: boolean
  additional: boolean
  trackIds: number[]
  tracks: CreditTrack[]
}

interface CreditEditorPayload {
  discogs: {
    selectedKey?: string | null
    selectedSourceType?: 'owned_copy' | 'musicbrainz' | null
    selectedOwnedCopyId?: number | null
    selectedReleaseId?: number | null
    sourceUrl?: string | null
    fetchedAt?: string | null
    options: Array<{
      key: string
      sourceType: 'owned_copy' | 'musicbrainz'
      ownedCopyId?: number | null
      format?: string | null
      releaseId: number
    }>
  }
  tracks: CreditTrack[]
  items: CreditItem[]
}

const props = defineProps<{ albumId: number }>()
const emit = defineEmits<{ updated: [] }>()
const { t } = useI18n()

const dialog = ref(false)
const loading = ref(false)
const saving = ref(false)
const actionKey = ref<string | null>(null)
const error = ref<string | null>(null)
const payload = ref<CreditEditorPayload>({
  discogs: { options: [] },
  tracks: [],
  items: [],
})
const selectedDiscogsKey = ref<string | null>(null)
const editingId = ref<number | null>(null)
const musician = ref<MusicianSummary | string | null>(null)
const role = ref('')
const creditedAs = ref('')
const scope = ref<'album' | 'tracks'>('album')
const trackIds = ref<number[]>([])
const guest = ref(false)
const additional = ref(false)
const deleteCandidate = ref<CreditItem | null>(null)
const discogsSaving = ref(false)

const musicianOptions = computed(() => {
  const unique = new Map<number, MusicianSummary>()
  payload.value.items.forEach((item) => unique.set(item.musician.id, item.musician))
  return [...unique.values()].sort((left, right) => left.name.localeCompare(right.name))
})
const discogsOptions = computed(() => payload.value.discogs.options.map((option) => ({
  title: discogsOptionLabel(option),
  value: option.key,
})))

async function open() {
  dialog.value = true
  resetForm()
  await load()
}

async function load() {
  loading.value = true
  error.value = null
  try {
    applyPayload(await apiRequest<CreditEditorPayload>(`/albums/${props.albumId}/musician-credits`))
  } catch (cause) {
    error.value = message(cause, 'albums.musicianCreditsLoadFailed')
  } finally {
    loading.value = false
  }
}

function edit(item: CreditItem) {
  if (!item.manual || item.id === null) return
  editingId.value = item.id
  musician.value = item.musician
  role.value = item.role
  creditedAs.value = item.creditedAs ?? ''
  scope.value = item.trackIds.length ? 'tracks' : 'album'
  trackIds.value = [...item.trackIds]
  guest.value = item.guest
  additional.value = item.additional
}

function resetForm() {
  editingId.value = null
  musician.value = null
  role.value = ''
  creditedAs.value = ''
  scope.value = 'album'
  trackIds.value = []
  guest.value = false
  additional.value = false
}

async function save() {
  const selectedMusician = musician.value
  const name = typeof selectedMusician === 'string' ? selectedMusician.trim() : null
  if ((!selectedMusician || (typeof selectedMusician === 'string' && !name)) || !role.value.trim()) return

  saving.value = true
  error.value = null
  try {
    const body = {
      musicianId: typeof selectedMusician === 'object' ? selectedMusician.id : null,
      name,
      role: role.value.trim(),
      creditedAs: creditedAs.value.trim() || null,
      guest: guest.value,
      additional: additional.value,
      trackIds: scope.value === 'tracks' ? trackIds.value : [],
    }
    const path = editingId.value === null
      ? `/albums/${props.albumId}/musician-credits`
      : `/albums/${props.albumId}/musician-credits/${editingId.value}`
    applyPayload(await apiRequest<CreditEditorPayload>(path, {
      method: editingId.value === null ? 'POST' : 'PATCH',
      body: JSON.stringify(body),
    }))
    resetForm()
    emit('updated')
  } catch (cause) {
    error.value = message(cause, 'albums.musicianCreditSaveFailed')
  } finally {
    saving.value = false
  }
}

async function remove() {
  const item = deleteCandidate.value
  if (!item?.id) return
  deleteCandidate.value = null
  await mutate(`manual-${item.id}`, `/albums/${props.albumId}/musician-credits/${item.id}`, 'DELETE')
}

async function toggleImported(item: CreditItem) {
  if (!item.sourceKey) return
  await mutate(
    `imported-${item.sourceKey}`,
    `/albums/${props.albumId}/musician-credits/suppressions/${item.sourceKey}`,
    item.hidden ? 'DELETE' : 'PUT',
  )
}

async function mutate(key: string, path: string, method: 'DELETE' | 'PUT') {
  actionKey.value = key
  error.value = null
  try {
    applyPayload(await apiRequest<CreditEditorPayload>(path, { method }))
    emit('updated')
  } catch (cause) {
    error.value = message(cause, 'albums.musicianCreditActionFailed')
  } finally {
    actionKey.value = null
  }
}

async function saveDiscogsSource(refresh = false) {
  const option = payload.value.discogs.options.find(candidate => candidate.key === selectedDiscogsKey.value)
  if (!option) return
  discogsSaving.value = true
  error.value = null
  try {
    applyPayload(await apiRequest<CreditEditorPayload>(
      `/albums/${props.albumId}/musician-credits/discogs-source`,
      {
        method: 'PUT',
        body: JSON.stringify({
          sourceType: option.sourceType,
          ownedCopyId: option.ownedCopyId,
          releaseId: option.releaseId,
          refresh,
        }),
      },
    ))
    emit('updated')
  } catch (cause) {
    error.value = message(cause, 'albums.discogsMusicianCreditsFailed')
  } finally {
    discogsSaving.value = false
  }
}

async function clearDiscogsSource() {
  discogsSaving.value = true
  error.value = null
  try {
    applyPayload(await apiRequest<CreditEditorPayload>(
      `/albums/${props.albumId}/musician-credits/discogs-source`,
      { method: 'DELETE' },
    ))
    emit('updated')
  } catch (cause) {
    error.value = message(cause, 'albums.discogsMusicianCreditsFailed')
  } finally {
    discogsSaving.value = false
  }
}

function applyPayload(next: CreditEditorPayload) {
  payload.value = next
  selectedDiscogsKey.value = next.discogs.selectedKey ?? null
}

function discogsOptionLabel(option: CreditEditorPayload['discogs']['options'][number]) {
  const format = option.format ? t(`albums.physicalFormats.${option.format}`) : null
  const source = option.sourceType === 'owned_copy'
    ? t('albums.discogsOwnedSource')
    : t('albums.discogsMusicBrainzSource')
  return [source, format, t('albums.discogsRelease', { id: option.releaseId })].filter(Boolean).join(' · ')
}

function providerLabel(provider: string) {
  if (provider === 'discogs') return 'Discogs'
  if (provider === 'musicbrainz') return 'MusicBrainz'
  return t('albums.manualMusicianCredit')
}

function trackLabel(track: CreditTrack) {
  const number = [track.discNumber, track.trackNumber]
    .filter((value) => value !== null && value !== undefined)
    .join('.')
  return number ? `${number} ${track.title}` : track.title
}

function scopeLabel(item: CreditItem) {
  if (!item.tracks.length) return t('albums.albumWideCredit')
  return item.tracks.map(trackLabel).join(', ')
}

function message(cause: unknown, fallbackKey: string) {
  return cause instanceof Error ? cause.message : t(fallbackKey)
}
</script>

<template>
  <v-btn prepend-icon="mdi-account-edit-outline" size="small" variant="tonal" @click="void open()">
    {{ t('albums.manageMusicians') }}
  </v-btn>

  <v-dialog v-model="dialog" max-width="920" scrollable>
    <v-card prepend-icon="mdi-account-edit-outline" :title="t('albums.musicianEditorTitle')">
      <template #append>
        <TooltipIconButton
          :aria-label="t('settings.close')"
          icon="mdi-close"
          :text="t('settings.close')"
          variant="text"
          @click="dialog = false"
        />
      </template>
      <v-card-text>
        <p class="text-body-2 text-medium-emphasis mb-4">{{ t('albums.musicianEditorHint') }}</p>
        <v-alert v-if="error" class="mb-4" closable type="error" variant="tonal" @click:close="error = null">
          {{ error }}
        </v-alert>
        <v-skeleton-loader v-if="loading" type="list-item-three-line@3" />
        <template v-else>
          <v-card
            v-if="payload.discogs.options.length"
            border
            class="mb-5"
            color="surface-variant"
            rounded="lg"
            variant="tonal"
          >
            <v-card-item prepend-icon="mdi-record-circle-outline">
              <v-card-title class="text-subtitle-1">{{ t('albums.discogsMusicianCredits') }}</v-card-title>
              <v-card-subtitle>{{ t('albums.discogsMusicianCreditsHint') }}</v-card-subtitle>
            </v-card-item>
            <v-card-text>
              <v-select
                v-model="selectedDiscogsKey"
                hide-details
                :items="discogsOptions"
                :label="t('albums.discogsMusicianSource')"
              />
            </v-card-text>
            <v-card-actions class="flex-wrap">
              <v-btn
                color="primary"
                :disabled="!selectedDiscogsKey"
                :loading="discogsSaving"
                prepend-icon="mdi-account-sync-outline"
                variant="tonal"
                @click="void saveDiscogsSource(false)"
              >
                {{ t(payload.discogs.selectedKey ? 'albums.useDiscogsMusicianSource' : 'albums.importDiscogsMusicians') }}
              </v-btn>
              <v-btn
                v-if="payload.discogs.selectedKey"
                :disabled="selectedDiscogsKey !== payload.discogs.selectedKey"
                :loading="discogsSaving"
                prepend-icon="mdi-refresh"
                variant="text"
                @click="void saveDiscogsSource(true)"
              >
                {{ t('albums.refreshDiscogsMusicians') }}
              </v-btn>
              <v-btn
                v-if="payload.discogs.selectedKey"
                :disabled="discogsSaving"
                prepend-icon="mdi-link-variant-off"
                variant="text"
                @click="void clearDiscogsSource()"
              >
                {{ t('albums.removeDiscogsMusicians') }}
              </v-btn>
              <v-btn
                v-if="payload.discogs.sourceUrl"
                append-icon="mdi-open-in-new"
                variant="text"
                @click="openExternalUrl(payload.discogs.sourceUrl)"
              >
                Discogs
              </v-btn>
            </v-card-actions>
          </v-card>

          <v-card border class="mb-5" rounded="lg" variant="flat">
            <v-card-item>
              <v-card-title class="text-subtitle-1">
                {{ editingId === null ? t('albums.addMusicianCredit') : t('albums.editMusicianCredit') }}
              </v-card-title>
            </v-card-item>
            <v-card-text>
              <v-row dense>
                <v-col cols="12" md="6">
                  <v-combobox
                    v-model="musician"
                    clearable
                    :items="musicianOptions"
                    item-title="name"
                    item-value="id"
                    :label="t('albums.musicianName')"
                    return-object
                  />
                </v-col>
                <v-col cols="12" md="6">
                  <v-text-field v-model="role" :label="t('albums.musicianRole')" />
                </v-col>
                <v-col cols="12" md="6">
                  <v-text-field v-model="creditedAs" clearable :label="t('albums.creditedAs')" />
                </v-col>
                <v-col cols="12" md="6">
                  <v-select
                    v-model="scope"
                    :items="[
                      { title: t('albums.albumWideCredit'), value: 'album' },
                      { title: t('albums.musicianSelectedTracks'), value: 'tracks' },
                    ]"
                    :label="t('albums.creditScope')"
                  />
                </v-col>
                <v-col v-if="scope === 'tracks'" cols="12">
                  <v-select
                    v-model="trackIds"
                    chips
                    clearable
                    closable-chips
                    :item-title="trackLabel"
                    item-value="id"
                    :items="payload.tracks"
                    :label="t('albums.musicianSelectTracks')"
                    multiple
                  />
                </v-col>
                <v-col cols="12" sm="6">
                  <v-checkbox v-model="guest" hide-details :label="t('albums.guestMusician')" />
                </v-col>
                <v-col cols="12" sm="6">
                  <v-checkbox v-model="additional" hide-details :label="t('albums.additionalMusician')" />
                </v-col>
              </v-row>
            </v-card-text>
            <v-card-actions>
              <v-spacer />
              <v-btn v-if="editingId !== null" @click="resetForm">{{ t('settings.cancel') }}</v-btn>
              <v-btn
                color="primary"
                :disabled="!musician || !role.trim() || (scope === 'tracks' && !trackIds.length)"
                :loading="saving"
                variant="flat"
                @click="void save()"
              >
                {{ editingId === null ? t('albums.addMusicianCredit') : t('settings.saveChanges') }}
              </v-btn>
            </v-card-actions>
          </v-card>

          <v-list v-if="payload.items.length" border lines="three" rounded="lg">
            <v-list-item v-for="item in payload.items" :key="item.manual ? `manual-${item.id}` : `source-${item.sourceKey}`">
              <template #prepend>
                <v-icon :class="{ 'text-disabled': item.hidden }" icon="mdi-account-music-outline" />
              </template>
              <v-list-item-title :class="{ 'text-disabled': item.hidden }">
                {{ item.musician.name }}
                <span v-if="item.creditedAs" class="text-medium-emphasis text-caption">
                  ({{ t('albums.creditedAsValue', { name: item.creditedAs }) }})
                </span>
              </v-list-item-title>
              <v-list-item-subtitle>{{ item.role }} · {{ scopeLabel(item) }}</v-list-item-subtitle>
              <div class="d-flex flex-wrap ga-2 mt-2">
                <v-chip size="x-small" :variant="item.manual ? 'tonal' : 'outlined'">
                  {{ providerLabel(item.provider) }}
                </v-chip>
                <v-chip v-if="item.hidden" color="warning" size="x-small" variant="tonal">
                  {{ t('albums.hiddenMusicianCredit') }}
                </v-chip>
                <v-chip v-if="item.guest" size="x-small" variant="tonal">{{ t('albums.guestMusician') }}</v-chip>
                <v-chip v-if="item.additional" size="x-small" variant="tonal">{{ t('albums.additionalMusician') }}</v-chip>
              </div>
              <template #append>
                <div class="d-flex align-center ga-1">
                  <template v-if="item.manual">
                    <TooltipIconButton
                      :aria-label="t('albums.editMusicianCredit')"
                      icon="mdi-pencil-outline"
                      :text="t('albums.editMusicianCredit')"
                      variant="text"
                      @click="edit(item)"
                    />
                    <TooltipIconButton
                      :aria-label="t('albums.deleteMusicianCredit')"
                      color="error"
                      icon="mdi-delete-outline"
                      :loading="actionKey === `manual-${item.id}`"
                      :text="t('albums.deleteMusicianCredit')"
                      variant="text"
                      @click="deleteCandidate = item"
                    />
                  </template>
                  <TooltipIconButton
                    v-else
                    :aria-label="item.hidden ? t('albums.restoreMusicianCredit') : t('albums.hideMusicianCredit')"
                    :icon="item.hidden ? 'mdi-eye-outline' : 'mdi-eye-off-outline'"
                    :loading="actionKey === `imported-${item.sourceKey}`"
                    :text="item.hidden ? t('albums.restoreMusicianCredit') : t('albums.hideMusicianCredit')"
                    variant="text"
                    @click="void toggleImported(item)"
                  />
                </div>
              </template>
            </v-list-item>
          </v-list>
          <v-alert v-else density="compact" icon="mdi-account-plus-outline" type="info" variant="tonal">
            {{ t('albums.noMusicianCreditsToManage') }}
          </v-alert>
        </template>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="dialog = false">{{ t('settings.close') }}</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-dialog :model-value="deleteCandidate !== null" max-width="480" @update:model-value="value => { if (!value) deleteCandidate = null }">
    <v-card prepend-icon="mdi-delete-outline" :title="t('albums.deleteMusicianCredit')">
      <v-card-text>{{ t('albums.deleteMusicianCreditConfirm') }}</v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="deleteCandidate = null">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="error" variant="flat" @click="void remove()">{{ t('settings.remove') }}</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type {
  DiscogsCollectionInstance,
  DiscogsLinkedReleaseDetails,
  DiscogsReleaseCandidate,
  OwnedAlbumCopy,
} from '@/stores/catalog'
import { useCatalogStore } from '@/stores/catalog'
import { openExternalUrl } from '@/utils/externalLinks'
import { formatDateTime } from '@/utils/formatters'

const props = defineProps<{
  albumId: number
  albumTitle: string
  artistName: string
  releaseYear?: number | null
  ownedCopy: OwnedAlbumCopy
}>()

const { locale, t } = useI18n()
const catalog = useCatalogStore()
const dialog = ref(false)
const loading = ref(false)
const detailsLoading = ref(false)
const refreshing = ref(false)
const savingReleaseId = ref<number | null>(null)
const error = ref<string | null>(null)
const statusError = ref<string | null>(null)
const candidates = ref<DiscogsReleaseCandidate[]>([])
const details = ref<DiscogsLinkedReleaseDetails | null>(null)
const unlinkDialog = ref(false)
const instanceDialog = ref(false)
const instances = ref<DiscogsCollectionInstance[]>([])
const selectedInstanceId = ref<number | null>(null)
const pendingCandidate = ref<DiscogsReleaseCandidate | null>(null)
const pendingRefresh = ref(false)
const form = reactive({
  artist: '',
  title: '',
  year: '',
  format: null as string | null,
  country: '',
  barcode: '',
  catalogNumber: '',
})

const linked = computed(() => props.ownedCopy.provider === 'discogs' && Boolean(props.ownedCopy.externalReleaseId))
const linkedUrl = computed(() => details.value?.release.webUrl ?? (props.ownedCopy.externalReleaseId
  ? `https://www.discogs.com/release/${props.ownedCopy.externalReleaseId}`
  : null))
const linkedTitle = computed(() => {
  if (details.value?.release.title) {
    return [details.value.release.artist, details.value.release.title].filter(Boolean).join(' - ')
  }

  return t('albums.discogsRelease', { id: props.ownedCopy.externalReleaseId })
})
const linkedDetails = computed(() => details.value ? candidateDetails({
  year: details.value.release.year,
  country: details.value.release.country,
  formats: details.value.release.formats,
  labels: details.value.release.labels,
  catalogNumber: details.value.release.catalogNumber,
}) : '')
const formatOptions = computed(() => [
  { title: t('albums.discogsAnyFormat'), value: null },
  { title: t('albums.physicalFormats.vinyl'), value: 'Vinyl' },
  { title: t('albums.physicalFormats.cd'), value: 'CD' },
  { title: t('albums.physicalFormats.blu_ray'), value: 'Blu-ray' },
  { title: t('albums.physicalFormats.dvd'), value: 'DVD' },
  { title: t('albums.physicalFormats.cassette'), value: 'Cassette' },
])

onMounted(() => void loadDetails())
watch(() => props.ownedCopy.externalReleaseId, () => void loadDetails())

function initialFormat() {
  return {
    vinyl: 'Vinyl',
    cd: 'CD',
    blu_ray: 'Blu-ray',
    dvd: 'DVD',
    cassette: 'Cassette',
  }[props.ownedCopy.physicalFormat ?? ''] ?? null
}

async function loadDetails() {
  details.value = null
  statusError.value = null
  if (!linked.value) return

  detailsLoading.value = true
  try {
    details.value = await catalog.loadOwnedCopyDiscogsDetails(props.albumId, props.ownedCopy.id)
  } catch (cause) {
    statusError.value = cause instanceof Error ? cause.message : t('albums.discogsDetailsFailed')
  } finally {
    detailsLoading.value = false
  }
}

async function openMatcher() {
  Object.assign(form, {
    artist: props.artistName,
    title: props.albumTitle,
    year: props.releaseYear?.toString() ?? '',
    format: initialFormat(),
    country: '',
    barcode: '',
    catalogNumber: '',
  })
  candidates.value = []
  error.value = null
  dialog.value = true
  await search()
}

async function search() {
  if (!form.artist.trim() || !form.title.trim()) return

  loading.value = true
  error.value = null
  try {
    candidates.value = await catalog.searchDiscogsReleases(props.albumId, {
      artist: form.artist.trim(),
      title: form.title.trim(),
      year: form.year ? Number(form.year) : null,
      format: form.format,
      country: form.country.trim() || null,
      barcode: form.barcode.trim() || null,
      catalogNumber: form.catalogNumber.trim() || null,
    })
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('albums.discogsSearchFailed')
  } finally {
    loading.value = false
  }
}

async function prepareLink(candidate: DiscogsReleaseCandidate) {
  if (!candidate.inCollection) {
    await link(candidate, null)
    return
  }

  savingReleaseId.value = candidate.releaseId
  error.value = null
  try {
    const collectionInstances = await catalog.loadDiscogsCollectionInstances(props.albumId, candidate.releaseId)
    if (collectionInstances.length <= 1) {
      await link(candidate, collectionInstances[0]?.instanceId ?? null)
      return
    }

    pendingCandidate.value = candidate
    pendingRefresh.value = false
    instances.value = collectionInstances
    selectedInstanceId.value = null
    instanceDialog.value = true
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('albums.discogsLinkFailed')
  } finally {
    savingReleaseId.value = null
  }
}

async function link(candidate: DiscogsReleaseCandidate, collectionInstanceId: number | null) {
  savingReleaseId.value = candidate.releaseId
  error.value = null
  try {
    await catalog.linkOwnedCopyToDiscogs(
      props.albumId,
      props.ownedCopy.id,
      candidate.releaseId,
      collectionInstanceId,
    )
    dialog.value = false
    instanceDialog.value = false
    await loadDetails()
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('albums.discogsLinkFailed')
  } finally {
    savingReleaseId.value = null
  }
}

async function prepareRefresh() {
  if (!props.ownedCopy.externalReleaseId) return

  refreshing.value = true
  statusError.value = null
  try {
    const collectionInstances = await catalog.loadDiscogsCollectionInstances(
      props.albumId,
      props.ownedCopy.externalReleaseId,
      true,
    )
    const currentInstance = collectionInstances.find(
      instance => instance.instanceId === props.ownedCopy.externalCollectionInstanceId,
    )
    if (currentInstance || collectionInstances.length <= 1) {
      await refresh(currentInstance?.instanceId ?? collectionInstances[0]?.instanceId ?? null)
      return
    }

    pendingCandidate.value = null
    pendingRefresh.value = true
    instances.value = collectionInstances
    selectedInstanceId.value = null
    instanceDialog.value = true
  } catch (cause) {
    statusError.value = cause instanceof Error ? cause.message : t('albums.discogsRefreshFailed')
  } finally {
    refreshing.value = false
  }
}

async function refresh(collectionInstanceId: number | null) {
  refreshing.value = true
  statusError.value = null
  try {
    await catalog.refreshOwnedCopyDiscogsLink(props.albumId, props.ownedCopy.id, collectionInstanceId)
    instanceDialog.value = false
    await loadDetails()
  } catch (cause) {
    statusError.value = cause instanceof Error ? cause.message : t('albums.discogsRefreshFailed')
  } finally {
    refreshing.value = false
  }
}

async function confirmInstance() {
  if (!selectedInstanceId.value) return

  if (pendingRefresh.value) {
    await refresh(selectedInstanceId.value)
  } else if (pendingCandidate.value) {
    await link(pendingCandidate.value, selectedInstanceId.value)
  }
}

async function unlink() {
  loading.value = true
  statusError.value = null
  try {
    await catalog.unlinkOwnedCopyFromDiscogs(props.albumId, props.ownedCopy.id)
    details.value = null
    unlinkDialog.value = false
  } catch (cause) {
    statusError.value = cause instanceof Error ? cause.message : t('albums.discogsUnlinkFailed')
  } finally {
    loading.value = false
  }
}

function candidateDetails(candidate: Pick<DiscogsReleaseCandidate, 'year' | 'country' | 'formats' | 'labels' | 'catalogNumber'>) {
  return [
    candidate.year,
    candidate.country,
    candidate.formats.join(', '),
    candidate.labels.join(', '),
    candidate.catalogNumber,
  ].filter(Boolean).join(' · ')
}

function instanceLabel(instance: DiscogsCollectionInstance) {
  const folder = instance.folderName ?? t('albums.discogsFolder', { id: instance.folderId })
  const added = instance.dateAdded ? formatDateTime(instance.dateAdded, locale.value) : null

  return [folder, added, t('albums.discogsInstance', { id: instance.instanceId })].filter(Boolean).join(' · ')
}
</script>

<template>
  <div class="discogs-match mt-3">
    <v-alert v-if="statusError" class="mb-2" density="compact" type="error" variant="tonal">
      {{ statusError }}
    </v-alert>
    <div v-if="linked" class="linked-release">
      <v-avatar color="surface-variant" rounded="lg" size="48">
        <v-img v-if="details?.release.thumbnailUrl" :src="details.release.thumbnailUrl" />
        <v-icon v-else icon="mdi-album" />
      </v-avatar>
      <div class="linked-release-copy">
        <button class="discogs-link text-body-2 font-weight-medium" type="button" @click="openExternalUrl(linkedUrl)">
          {{ linkedTitle }}
        </button>
        <v-skeleton-loader v-if="detailsLoading" type="text" width="180" />
        <div v-else-if="linkedDetails" class="text-caption text-medium-emphasis">{{ linkedDetails }}</div>
        <div v-if="details?.collectionInstance" class="text-caption text-medium-emphasis">
          {{ details.collectionInstance.folderName ?? t('albums.discogsInCollection') }}
          · {{ t('albums.discogsInstance', { id: details.collectionInstance.instanceId }) }}
        </div>
      </div>
      <div class="linked-release-actions">
        <TooltipIconButton
          :aria-label="t('albums.discogsChangeMatch')"
          icon="mdi-link-variant"
          size="small"
          :text="t('albums.discogsChangeMatch')"
          variant="text"
          @click="void openMatcher()"
        />
        <TooltipIconButton
          :aria-label="t('albums.discogsRefresh')"
          icon="mdi-refresh"
          :loading="refreshing"
          size="small"
          :text="t('albums.discogsRefresh')"
          variant="text"
          @click="void prepareRefresh()"
        />
        <TooltipIconButton
          :aria-label="t('albums.discogsUnlink')"
          icon="mdi-link-variant-off"
          size="small"
          :text="t('albums.discogsUnlink')"
          variant="text"
          @click="unlinkDialog = true"
        />
      </div>
    </div>
    <v-btn v-else color="primary" prepend-icon="mdi-link-variant" size="small" variant="tonal" @click="void openMatcher()">
      {{ t('albums.discogsMatch') }}
    </v-btn>
  </div>

  <v-dialog v-model="dialog" max-width="900" scrollable>
    <v-card prepend-icon="mdi-album" :title="t('albums.discogsMatchTitle')">
      <v-card-text>
        <v-alert v-if="error" class="mb-4" type="error" variant="tonal">{{ error }}</v-alert>
        <p class="text-body-2 text-medium-emphasis mb-4">{{ t('albums.discogsMatchHint') }}</p>
        <v-row dense>
          <v-col cols="12" sm="6">
            <v-text-field v-model="form.artist" density="compact" :label="t('tracks.artist')" />
          </v-col>
          <v-col cols="12" sm="6">
            <v-text-field v-model="form.title" density="compact" :label="t('tracks.album')" />
          </v-col>
          <v-col cols="6" sm="3">
            <v-text-field v-model="form.year" density="compact" inputmode="numeric" :label="t('albums.releaseYear')" />
          </v-col>
          <v-col cols="6" sm="3">
            <v-select v-model="form.format" density="compact" :items="formatOptions" :label="t('albums.physicalFormat')" />
          </v-col>
          <v-col cols="6" sm="3">
            <v-text-field v-model="form.country" density="compact" :label="t('albums.discogsCountry')" />
          </v-col>
          <v-col cols="6" sm="3">
            <v-text-field v-model="form.catalogNumber" density="compact" :label="t('albums.discogsCatalogNumber')" />
          </v-col>
          <v-col cols="12" sm="6">
            <v-text-field v-model="form.barcode" density="compact" :label="t('albums.discogsBarcode')" />
          </v-col>
          <v-col class="d-flex align-start" cols="12" sm="6">
            <v-btn color="primary" :loading="loading" prepend-icon="mdi-magnify" variant="flat" @click="void search()">
              {{ t('albums.discogsSearch') }}
            </v-btn>
          </v-col>
        </v-row>

        <v-skeleton-loader v-if="loading" class="mt-4" type="list-item-avatar-three-line@3" />
        <v-alert v-else-if="!candidates.length" class="mt-4" type="info" variant="tonal">
          {{ t('albums.discogsNoCandidates') }}
        </v-alert>
        <v-list v-else class="mt-4" border rounded="lg" lines="three">
          <v-list-item v-for="candidate in candidates" :key="candidate.releaseId">
            <template #prepend>
              <v-avatar color="surface-variant" rounded="lg" size="64">
                <v-img v-if="candidate.thumbnailUrl" :src="candidate.thumbnailUrl" />
                <v-icon v-else icon="mdi-album" />
              </v-avatar>
            </template>
            <v-list-item-title>{{ candidate.title }}</v-list-item-title>
            <v-list-item-subtitle>{{ candidateDetails(candidate) }}</v-list-item-subtitle>
            <v-list-item-subtitle>
              <v-chip v-if="candidate.inCollection" class="mt-1" color="success" size="x-small" variant="tonal">
                {{ t('albums.discogsInCollection') }}
              </v-chip>
            </v-list-item-subtitle>
            <template #append>
              <div class="d-flex align-center ga-1">
                <TooltipIconButton
                  :aria-label="t('albums.discogsOpen')"
                  icon="mdi-open-in-new"
                  size="small"
                  :text="t('albums.discogsOpen')"
                  variant="text"
                  @click="openExternalUrl(candidate.webUrl)"
                />
                <v-btn
                  color="primary"
                  :loading="savingReleaseId === candidate.releaseId"
                  size="small"
                  variant="tonal"
                  @click="void prepareLink(candidate)"
                >
                  {{ t('albums.discogsLink') }}
                </v-btn>
              </div>
            </template>
          </v-list-item>
        </v-list>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="dialog = false">{{ t('settings.close') }}</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-dialog v-model="instanceDialog" max-width="620">
    <v-card prepend-icon="mdi-folder-account-outline" :title="t('albums.discogsChooseInstanceTitle')">
      <v-card-text>
        <v-alert v-if="error || statusError" class="mb-3" type="error" variant="tonal">
          {{ error ?? statusError }}
        </v-alert>
        <p class="text-body-2 text-medium-emphasis mb-3">{{ t('albums.discogsChooseInstanceHint') }}</p>
        <v-radio-group v-model="selectedInstanceId">
          <v-radio
            v-for="instance in instances"
            :key="instance.instanceId"
            :label="instanceLabel(instance)"
            :value="instance.instanceId"
          />
        </v-radio-group>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn :disabled="refreshing || savingReleaseId !== null" @click="instanceDialog = false">
          {{ t('settings.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          :disabled="!selectedInstanceId"
          :loading="refreshing || savingReleaseId !== null"
          variant="flat"
          @click="void confirmInstance()"
        >
          {{ t(pendingRefresh ? 'albums.discogsRefresh' : 'albums.discogsLink') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-dialog v-model="unlinkDialog" max-width="480">
    <v-card prepend-icon="mdi-link-variant-off" :title="t('albums.discogsUnlinkTitle')">
      <v-card-text>{{ t('albums.discogsUnlinkConfirm') }}</v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn :disabled="loading" @click="unlinkDialog = false">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="primary" :loading="loading" variant="flat" @click="void unlink()">
          {{ t('albums.discogsUnlink') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.linked-release {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
}

.linked-release-copy {
  min-width: 0;
}

.linked-release-actions {
  display: flex;
  align-items: center;
}

.discogs-link {
  max-width: 100%;
  overflow: hidden;
  color: rgb(var(--v-theme-primary));
  text-align: left;
  text-decoration: underline;
  text-overflow: ellipsis;
  text-underline-offset: 0.15em;
  white-space: nowrap;
}

@media (max-width: 520px) {
  .linked-release {
    grid-template-columns: auto minmax(0, 1fr);
  }

  .linked-release-actions {
    grid-column: 1 / -1;
    justify-content: flex-end;
  }
}
</style>

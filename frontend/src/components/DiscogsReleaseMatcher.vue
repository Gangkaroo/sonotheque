<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import type { DiscogsReleaseCandidate, OwnedAlbumCopy } from '@/stores/catalog'
import { useCatalogStore } from '@/stores/catalog'
import { openExternalUrl } from '@/utils/externalLinks'
import TooltipIconButton from '@/components/TooltipIconButton.vue'

const props = defineProps<{
  albumId: number
  albumTitle: string
  artistName: string
  releaseYear?: number | null
  ownedCopy: OwnedAlbumCopy
}>()

const { t } = useI18n()
const catalog = useCatalogStore()
const dialog = ref(false)
const loading = ref(false)
const savingReleaseId = ref<number | null>(null)
const error = ref<string | null>(null)
const candidates = ref<DiscogsReleaseCandidate[]>([])
const unlinkDialog = ref(false)
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
const linkedUrl = computed(() => props.ownedCopy.externalReleaseId
  ? `https://www.discogs.com/release/${props.ownedCopy.externalReleaseId}`
  : null)
const formatOptions = computed(() => [
  { title: t('albums.discogsAnyFormat'), value: null },
  { title: t('albums.physicalFormats.vinyl'), value: 'Vinyl' },
  { title: t('albums.physicalFormats.cd'), value: 'CD' },
  { title: t('albums.physicalFormats.blu_ray'), value: 'Blu-ray' },
  { title: t('albums.physicalFormats.dvd'), value: 'DVD' },
  { title: t('albums.physicalFormats.cassette'), value: 'Cassette' },
])

function initialFormat() {
  return {
    vinyl: 'Vinyl',
    cd: 'CD',
    blu_ray: 'Blu-ray',
    dvd: 'DVD',
    cassette: 'Cassette',
  }[props.ownedCopy.physicalFormat ?? ''] ?? null
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

async function link(candidate: DiscogsReleaseCandidate) {
  savingReleaseId.value = candidate.releaseId
  error.value = null
  try {
    await catalog.linkOwnedCopyToDiscogs(props.albumId, props.ownedCopy.id, candidate.releaseId)
    dialog.value = false
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('albums.discogsLinkFailed')
  } finally {
    savingReleaseId.value = null
  }
}

async function unlink() {
  loading.value = true
  error.value = null
  try {
    await catalog.unlinkOwnedCopyFromDiscogs(props.albumId, props.ownedCopy.id)
    unlinkDialog.value = false
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('albums.discogsUnlinkFailed')
  } finally {
    loading.value = false
  }
}

function candidateDetails(candidate: DiscogsReleaseCandidate) {
  return [
    candidate.year,
    candidate.country,
    candidate.formats.join(', '),
    candidate.labels.join(', '),
    candidate.catalogNumber,
  ].filter(Boolean).join(' · ')
}
</script>

<template>
  <div class="discogs-match mt-3">
    <div class="d-flex align-center flex-wrap ga-2">
      <v-icon color="primary" icon="mdi-album" size="small" />
      <template v-if="linked">
        <button class="discogs-link text-body-2 font-weight-medium" type="button" @click="openExternalUrl(linkedUrl)">
          {{ t('albums.discogsRelease', { id: ownedCopy.externalReleaseId }) }}
        </button>
        <v-chip v-if="ownedCopy.externalCollectionInstanceId" color="success" size="x-small" variant="tonal">
          {{ t('albums.discogsInCollection') }}
        </v-chip>
        <v-btn size="small" variant="text" @click="void openMatcher()">
          {{ t('albums.discogsChangeMatch') }}
        </v-btn>
        <v-btn size="small" variant="text" @click="unlinkDialog = true">
          {{ t('albums.discogsUnlink') }}
        </v-btn>
      </template>
      <v-btn v-else color="primary" prepend-icon="mdi-link-variant" size="small" variant="tonal" @click="void openMatcher()">
        {{ t('albums.discogsMatch') }}
      </v-btn>
    </div>
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
                  @click="void link(candidate)"
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
.discogs-link {
  color: rgb(var(--v-theme-primary));
  text-decoration: underline;
  text-underline-offset: 0.15em;
}
</style>

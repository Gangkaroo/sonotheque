<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import DiscogsReleaseMatcher from '@/components/DiscogsReleaseMatcher.vue'
import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type { OwnedAlbumCopy, OwnedAlbumCopyValues } from '@/stores/catalog'
import { useCatalogStore } from '@/stores/catalog'
import { formatDateOnly } from '@/utils/formatters'

const props = defineProps<{
  albumId: number
  albumTitle: string
  artistName: string
  releaseYear?: number | null
  copies: OwnedAlbumCopy[]
}>()

const { locale, t } = useI18n()
const catalog = useCatalogStore()
const dialog = ref(false)
const loading = ref(false)
const error = ref<string | null>(null)
const editingCopy = ref<OwnedAlbumCopy | null>(null)
const deletingCopy = ref<OwnedAlbumCopy | null>(null)
const form = reactive({
  isPhysical: true,
  physicalFormat: null as string | null,
  purchaseSource: '',
  purchaseDate: '',
  purchasePriceAmount: '',
  purchasePriceCurrency: '',
  mediaCondition: '',
  sleeveCondition: '',
  notes: '',
})
const physicalFormatOptions = computed(() => [
  { title: t('albums.physicalFormats.vinyl'), value: 'vinyl' },
  { title: t('albums.physicalFormats.cd'), value: 'cd' },
  { title: t('albums.physicalFormats.blu_ray'), value: 'blu_ray' },
  { title: t('albums.physicalFormats.dvd'), value: 'dvd' },
  { title: t('albums.physicalFormats.cassette'), value: 'cassette' },
])

function openCreate() {
  editingCopy.value = null
  Object.assign(form, {
    isPhysical: true,
    physicalFormat: null,
    purchaseSource: '',
    purchaseDate: '',
    purchasePriceAmount: '',
    purchasePriceCurrency: '',
    mediaCondition: '',
    sleeveCondition: '',
    notes: '',
  })
  error.value = null
  dialog.value = true
}

function openEdit(copy: OwnedAlbumCopy) {
  editingCopy.value = copy
  Object.assign(form, {
    isPhysical: copy.isPhysical,
    physicalFormat: copy.physicalFormat ?? null,
    purchaseSource: copy.purchaseSource ?? '',
    purchaseDate: copy.purchaseDate ?? '',
    purchasePriceAmount: copy.purchasePriceAmount ?? '',
    purchasePriceCurrency: copy.purchasePriceCurrency ?? '',
    mediaCondition: copy.mediaCondition ?? '',
    sleeveCondition: copy.sleeveCondition ?? '',
    notes: copy.notes ?? '',
  })
  error.value = null
  dialog.value = true
}

async function save() {
  const values: OwnedAlbumCopyValues = {
    isPhysical: form.isPhysical,
    physicalFormat: form.isPhysical ? form.physicalFormat : null,
    purchaseSource: form.purchaseSource.trim() || null,
    purchaseDate: form.purchaseDate || null,
    purchasePriceAmount: form.purchasePriceAmount.trim() || null,
    purchasePriceCurrency: form.purchasePriceCurrency.trim().toUpperCase() || null,
    mediaCondition: form.mediaCondition.trim() || null,
    sleeveCondition: form.sleeveCondition.trim() || null,
    notes: form.notes.trim() || null,
  }

  loading.value = true
  error.value = null
  try {
    if (editingCopy.value) {
      await catalog.updateOwnedAlbumCopy(props.albumId, editingCopy.value.id, values)
    } else {
      await catalog.createOwnedAlbumCopy(props.albumId, values)
    }
    dialog.value = false
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('albums.ownedCopySaveFailed')
  } finally {
    loading.value = false
  }
}

async function remove() {
  if (!deletingCopy.value) return

  loading.value = true
  error.value = null
  try {
    await catalog.deleteOwnedAlbumCopy(props.albumId, deletingCopy.value.id)
    deletingCopy.value = null
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('albums.ownedCopyDeleteFailed')
  } finally {
    loading.value = false
  }
}

function copyTitle(copy: OwnedAlbumCopy, index: number) {
  const format = copy.isPhysical && copy.physicalFormat
    ? t(`albums.physicalFormats.${copy.physicalFormat}`)
    : t(copy.isPhysical ? 'albums.physicalCopy' : 'albums.digitalCopy')

  return t('albums.ownedCopyTitle', { number: index + 1, format })
}

function copyFacts(copy: OwnedAlbumCopy) {
  return [
    copy.purchaseSource ? { icon: 'mdi-storefront-outline', value: copy.purchaseSource } : null,
    copy.purchaseDate ? {
      icon: 'mdi-calendar-check-outline',
      value: formatDateOnly(copy.purchaseDate, locale.value),
    } : null,
    copy.purchasePriceAmount ? {
      icon: 'mdi-cash',
      value: [copy.purchasePriceAmount, copy.purchasePriceCurrency].filter(Boolean).join(' '),
    } : null,
    copy.mediaCondition ? {
      icon: 'mdi-disc',
      value: t('albums.mediaConditionValue', { value: copy.mediaCondition }),
    } : null,
    copy.sleeveCondition ? {
      icon: 'mdi-album',
      value: t('albums.sleeveConditionValue', { value: copy.sleeveCondition }),
    } : null,
  ].filter((fact): fact is { icon: string, value: string } => fact !== null)
}
</script>

<template>
  <div class="owned-copies">
    <div class="owned-copies-heading">
      <div class="text-caption text-medium-emphasis">{{ t('albums.ownedCopies') }}</div>
      <v-btn color="primary" prepend-icon="mdi-plus" size="small" variant="tonal" @click="openCreate">
        {{ t('albums.addOwnedCopy') }}
      </v-btn>
    </div>

    <div v-if="copies.length" class="owned-copy-grid">
      <v-card v-for="(copy, index) in copies" :key="copy.id" class="owned-copy-card" variant="tonal">
        <v-card-item class="owned-copy-card-heading">
          <template #prepend>
            <v-icon color="primary" :icon="copy.isPhysical ? 'mdi-disc' : 'mdi-download-outline'" />
          </template>
          <v-card-title class="text-subtitle-2">{{ copyTitle(copy, index) }}</v-card-title>
          <template #append>
            <div class="d-flex">
              <TooltipIconButton
                :aria-label="t('albums.editOwnedCopy')"
                icon="mdi-pencil-outline"
                size="small"
                :text="t('albums.editOwnedCopy')"
                variant="text"
                @click="openEdit(copy)"
              />
              <TooltipIconButton
                :aria-label="t('albums.deleteOwnedCopy')"
                icon="mdi-delete-outline"
                size="small"
                :text="t('albums.deleteOwnedCopy')"
                variant="text"
                @click="deletingCopy = copy"
              />
            </div>
          </template>
        </v-card-item>
        <v-card-text class="pt-1">
          <div v-if="copyFacts(copy).length" class="copy-facts">
            <span v-for="fact in copyFacts(copy)" :key="`${fact.icon}-${fact.value}`" class="copy-fact text-caption">
              <v-icon :icon="fact.icon" size="x-small" />
              {{ fact.value }}
            </span>
          </div>
          <p v-if="copy.notes" class="copy-notes text-body-2 mt-2">{{ copy.notes }}</p>
          <DiscogsReleaseMatcher
            :album-id="albumId"
            :album-title="albumTitle"
            :artist-name="artistName"
            :owned-copy="copy"
            :release-year="releaseYear"
          />
        </v-card-text>
      </v-card>
    </div>
    <div v-else class="text-body-2 text-medium-emphasis">{{ t('albums.noOwnedCopies') }}</div>
  </div>

  <v-dialog v-model="dialog" max-width="680" scrollable>
    <v-card
      :prepend-icon="editingCopy ? 'mdi-pencil-outline' : 'mdi-plus'"
      :title="t(editingCopy ? 'albums.editOwnedCopy' : 'albums.addOwnedCopy')"
    >
      <v-card-text>
        <v-alert v-if="error" class="mb-4" type="error" variant="tonal">{{ error }}</v-alert>
        <v-switch v-model="form.isPhysical" color="primary" hide-details :label="t('albums.hasPhysicalCopy')" />
        <v-select
          v-model="form.physicalFormat"
          class="mt-3"
          clearable
          :disabled="!form.isPhysical"
          :items="physicalFormatOptions"
          :label="t('albums.physicalFormat')"
          prepend-inner-icon="mdi-disc-player"
        />
        <v-row dense>
          <v-col cols="12" sm="7">
            <v-text-field
              v-model="form.purchaseSource"
              clearable
              maxlength="255"
              :label="t('albums.purchaseSource')"
              prepend-inner-icon="mdi-storefront-outline"
            />
          </v-col>
          <v-col cols="12" sm="5">
            <v-text-field
              v-model="form.purchaseDate"
              clearable
              :label="t('albums.purchaseDate')"
              prepend-inner-icon="mdi-calendar-check-outline"
              type="date"
            />
          </v-col>
          <v-col cols="8">
            <v-text-field
              v-model="form.purchasePriceAmount"
              inputmode="decimal"
              :label="t('albums.purchasePrice')"
              min="0"
              prepend-inner-icon="mdi-cash"
              type="number"
            />
          </v-col>
          <v-col cols="4">
            <v-text-field v-model="form.purchasePriceCurrency" :label="t('albums.currency')" maxlength="3" />
          </v-col>
          <v-col cols="12" sm="6">
            <v-text-field v-model="form.mediaCondition" :label="t('albums.mediaCondition')" maxlength="32" />
          </v-col>
          <v-col cols="12" sm="6">
            <v-text-field v-model="form.sleeveCondition" :label="t('albums.sleeveCondition')" maxlength="32" />
          </v-col>
        </v-row>
        <v-textarea
          v-model="form.notes"
          auto-grow
          clearable
          counter="10000"
          :label="t('albums.copyNotes')"
          maxlength="10000"
          rows="2"
        />
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn :disabled="loading" @click="dialog = false">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="primary" :loading="loading" variant="flat" @click="void save()">
          {{ t('albums.saveOwnedCopy') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-dialog :model-value="deletingCopy !== null" max-width="500" @update:model-value="!$event && (deletingCopy = null)">
    <v-card prepend-icon="mdi-delete-outline" :title="t('albums.deleteOwnedCopy')">
      <v-card-text>
        <v-alert v-if="error" class="mb-4" type="error" variant="tonal">{{ error }}</v-alert>
        {{ t('albums.deleteOwnedCopyConfirm') }}
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn :disabled="loading" @click="deletingCopy = null">{{ t('settings.cancel') }}</v-btn>
        <v-btn color="primary" :loading="loading" variant="flat" @click="void remove()">
          {{ t('albums.deleteOwnedCopy') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.owned-copies-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 8px;
}

.owned-copy-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr));
  gap: 10px;
}

.owned-copy-card {
  min-width: 0;
}

.owned-copy-card-heading :deep(.v-card-item__append) {
  align-self: center;
}

.copy-facts {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 12px;
}

.copy-fact {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.copy-notes {
  white-space: pre-wrap;
}
</style>

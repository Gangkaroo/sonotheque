<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import TooltipIconButton from '@/components/TooltipIconButton.vue'
import type { MusicBrainzReleaseCandidate } from '@/types/musicianCredits'
import { openExternalUrl } from '@/utils/externalLinks'

defineProps<{ candidates: MusicBrainzReleaseCandidate[] }>()
const selectedReleaseId = defineModel<string | null>({ required: true })
const { t } = useI18n()

function details(candidate: MusicBrainzReleaseCandidate) {
  return [
    candidate.date,
    candidate.country,
    candidate.formats.join(', ') || null,
    candidate.trackCount ? t('albums.trackCount', { count: candidate.trackCount }) : null,
    candidate.barcode ? t('albums.musicianReleaseBarcode', { barcode: candidate.barcode }) : null,
  ].filter(Boolean).join(' · ')
}
</script>

<template>
  <v-radio-group v-model="selectedReleaseId" hide-details>
    <v-list border lines="three" rounded="lg">
      <v-list-item
        v-for="candidate in candidates"
        :key="candidate.id"
        class="musician-release-candidate"
        @click="selectedReleaseId = candidate.id"
      >
        <template #prepend>
          <v-radio class="mr-3" :value="candidate.id" @click.stop />
        </template>
        <v-list-item-title>{{ candidate.title }}</v-list-item-title>
        <v-list-item-subtitle v-if="candidate.artistName">{{ candidate.artistName }}</v-list-item-subtitle>
        <v-list-item-subtitle v-if="details(candidate)">{{ details(candidate) }}</v-list-item-subtitle>
        <template #append>
          <div class="d-flex align-center ga-2">
            <v-chip v-if="candidate.score" size="x-small" variant="tonal">
              {{ t('albums.musicianReleaseScore', { score: candidate.score }) }}
            </v-chip>
            <TooltipIconButton
              v-if="candidate.sourceUrl"
              :aria-label="t('albums.openMusicianRelease')"
              icon="mdi-open-in-new"
              size="small"
              :text="t('albums.openMusicianRelease')"
              variant="text"
              @click.stop="openExternalUrl(candidate.sourceUrl)"
            />
          </div>
        </template>
      </v-list-item>
    </v-list>
  </v-radio-group>
</template>

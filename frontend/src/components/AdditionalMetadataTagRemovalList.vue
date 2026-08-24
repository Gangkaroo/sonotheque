<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import type { AdditionalMetadataTag } from '@/stores/catalog'

const props = defineProps<{
  tags: AdditionalMetadataTag[]
  title: string
  hint: string
  totalTrackCount?: number
}>()

const selectedKeys = defineModel<string[]>({ required: true })
const { t } = useI18n()

function tagDetails(tag: AdditionalMetadataTag) {
  const details = [tag.frameId, tagValue(tag)]
  if ('trackCount' in tag && typeof tag.trackCount === 'number' && props.totalTrackCount) {
    details.push(t('tracks.additionalTagTrackCount', {
      count: tag.trackCount,
      total: props.totalTrackCount,
    }))
  }

  return details.join(' · ')
}

function tagValue(tag: AdditionalMetadataTag) {
  if (tag.values.length) return tag.values.join(', ')
  if (tag.sizeBytes !== undefined && tag.sizeBytes !== null) {
    return t('tracks.additionalTagSize', { size: tag.sizeBytes })
  }

  return t('tracks.additionalTagValueUnavailable')
}

function protectedTagHint(tag: AdditionalMetadataTag) {
  return t(tag.rating ? 'tracks.ratingTagManaged' : 'tracks.playbackStatisticsTagManaged')
}
</script>

<template>
  <div>
    <div class="text-subtitle-2">{{ title }}</div>
    <div class="text-caption text-medium-emphasis mb-2">{{ hint }}</div>
    <v-tooltip
      v-for="tag in tags"
      :key="tag.key"
      :disabled="!tag.protectedFromRemoval"
      location="top"
      :text="protectedTagHint(tag)"
    >
      <template #activator="{ props: tooltipProps }">
        <div v-bind="tooltipProps" class="tag-option">
          <v-checkbox
            v-model="selectedKeys"
            color="primary"
            density="compact"
            :disabled="tag.protectedFromRemoval"
            hide-details
            :value="tag.key"
          >
            <template #label>
              <span class="tag-label">
                {{ tag.name }}
                <span class="text-caption text-medium-emphasis ml-1">
                  {{ tagDetails(tag) }}
                </span>
              </span>
            </template>
          </v-checkbox>
        </div>
      </template>
    </v-tooltip>
  </div>
</template>

<style scoped>
.tag-label {
  overflow-wrap: anywhere;
}

.tag-option {
  width: fit-content;
}
</style>

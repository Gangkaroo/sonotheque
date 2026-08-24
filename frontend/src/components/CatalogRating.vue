<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'
import { useCatalogStore } from '@/stores/catalog'

const props = withDefaults(defineProps<{
  entityId: number
  entityType: 'album' | 'track'
  card?: boolean
  compact?: boolean
  modelValue?: number | null
  responsive?: boolean
  size?: number | string
}>(), {
  card: false,
  compact: false,
  modelValue: null,
  responsive: false,
  size: 20,
})
const emit = defineEmits<{
  'update:modelValue': [rating: number | null]
}>()
const { t } = useI18n()
const catalog = useCatalogStore()
const displayedRating = ref(props.modelValue ?? 0)
const saving = ref(false)
const error = ref('')
const errorVisible = ref(false)

watch(() => props.modelValue, (rating) => {
  if (!saving.value) displayedRating.value = rating ?? 0
})

async function saveRating(value: number) {
  if (saving.value) return

  const nextRating = value > 0 ? Math.round(value * 2) / 2 : null
  const previousRating = props.modelValue ?? null
  if (nextRating === previousRating) return

  displayedRating.value = nextRating ?? 0
  saving.value = true
  try {
    const path = `/${props.entityType}s/${props.entityId}/rating`
    let savedRating: number | null

    if (nextRating === null) {
      await apiRequest<void>(path, { method: 'DELETE' })
      savedRating = null
    } else {
      const result = await apiRequest<{ id: number, rating: number }>(path, {
          method: 'PATCH',
          body: JSON.stringify({ rating: nextRating }),
        })
      savedRating = result.rating
    }

    displayedRating.value = savedRating ?? 0
    emit('update:modelValue', savedRating)
    catalog.invalidateBrowseCache()
  } catch (cause) {
    displayedRating.value = previousRating ?? 0
    error.value = cause instanceof Error ? cause.message : t('ratings.updateFailed')
    errorVisible.value = true
  } finally {
    saving.value = false
  }
}

function handleRatingClick(event: MouseEvent) {
  const container = event.currentTarget as HTMLElement
  const rating = container.querySelector<HTMLElement>('.v-rating')
  if (!rating || !rating.contains(event.target as Node)) return

  const bounds = rating.getBoundingClientRect()
  if (bounds.width <= 0) return

  const starsFromLeft = ((event.clientX - bounds.left) / bounds.width) * 5
  if (starsFromLeft < 0 || starsFromLeft >= 0.25) return

  event.preventDefault()
  event.stopPropagation()
  void saveRating(0)
}
</script>

<template>
  <span
    class="catalog-rating"
    :class="{
      'catalog-rating--card': card,
      'catalog-rating--compact': compact,
      'catalog-rating--responsive': responsive,
      'catalog-rating--saving': saving,
      'catalog-rating--unrated': displayedRating === 0,
    }"
    @click.capture="handleRatingClick"
    @click.stop
    @keydown.stop
  >
    <v-rating
      :aria-label="t(entityType === 'album' ? 'ratings.albumLabel' : 'ratings.trackLabel')"
      active-color="primary"
      clearable
      color="medium-emphasis"
      density="compact"
      half-increments
      hover
      :length="5"
      :model-value="displayedRating"
      :readonly="saving"
      :size="size"
      @update:model-value="saveRating(Number($event))"
    />
  </span>

  <v-snackbar v-model="errorVisible" color="error" location="top" timeout="4000">
    {{ error }}
  </v-snackbar>
</template>

<style scoped>
.catalog-rating {
  display: inline-flex;
  line-height: 1;
  transition: opacity 120ms ease;
}

.catalog-rating--saving {
  opacity: 0.6;
}

.catalog-rating--card :deep(.v-rating) {
  gap: 2px;
}

.catalog-rating--compact :deep(.v-rating__wrapper),
.catalog-rating--compact :deep(.v-rating__item),
.catalog-rating--compact :deep(.v-btn),
.catalog-rating--compact :deep(.v-btn__underlay) {
  min-width: 19px !important;
  width: 19px !important;
}

.catalog-rating--compact :deep(.v-btn),
.catalog-rating--compact :deep(.v-btn__underlay) {
  height: 19px !important;
}

.catalog-rating--compact :deep(.v-btn__content),
.catalog-rating--compact :deep(.v-icon) {
  font-size: 17px !important;
  height: 19px;
  width: 19px;
}

.catalog-rating--unrated :deep(.v-btn) {
  color: rgba(var(--v-theme-on-surface), 0.32) !important;
}

@media (max-width: 1279px) {
  .catalog-rating--responsive {
    display: none;
  }
}
</style>

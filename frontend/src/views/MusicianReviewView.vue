<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import CatalogPagination from '@/components/CatalogPagination.vue'
import EmptyCatalogState from '@/components/EmptyCatalogState.vue'
import MusicBrainzReleaseChooser from '@/components/MusicBrainzReleaseChooser.vue'
import MusicianCreditsEditor from '@/components/MusicianCreditsEditor.vue'
import PageHeader from '@/components/PageHeader.vue'
import { useLibraryRootScopeStore } from '@/stores/libraryRootScope'
import {
  useMusicianReviewsStore,
  type MusicianReviewDecision,
  type MusicianReviewItem,
  type MusicianReviewStatus,
} from '@/stores/musicianReviews'
import { formatDateTime } from '@/utils/formatters'

const { locale, t } = useI18n()
const route = useRoute()
const router = useRouter()
const libraryRootScope = useLibraryRootScopeStore()
const reviews = useMusicianReviewsStore()
const status = ref<MusicianReviewStatus>(reviewStatus(route.query.status))
const page = ref(reviewPage(route.query.page))
const selectedReleases = reactive<Record<number, string | null>>({})
const actionKey = ref<string | null>(null)
const notice = ref('')
const noticeVisible = ref(false)

watch(
  [status, page, () => libraryRootScope.selectedRootId],
  () => void load(),
  { immediate: true },
)
watch(status, () => {
  if (page.value !== 1) page.value = 1
})
watch([status, page], syncRoute)
watch(() => route.query, () => {
  if (route.name !== 'musician-review') return

  const nextStatus = reviewStatus(route.query.status)
  const nextPage = reviewPage(route.query.page)
  if (status.value !== nextStatus) status.value = nextStatus
  if (page.value !== nextPage) page.value = nextPage
})

async function load() {
  await reviews.load(status.value, page.value)
  reviews.results.items.forEach((item) => {
    if (selectedReleases[item.album.id] === undefined) {
      selectedReleases[item.album.id] = item.candidateReleases[0]?.id ?? null
    }
  })
}

async function selectRelease(item: MusicianReviewItem) {
  const releaseId = selectedReleases[item.album.id]
  if (!releaseId) return

  await runAction(
    `release-${item.album.id}`,
    () => reviews.selectRelease(item.album.id, releaseId),
    t('musicianReview.releaseQueued'),
  )
}

async function retry(item: MusicianReviewItem) {
  await runAction(
    `retry-${item.album.id}`,
    () => reviews.retry(item.album.id),
    t('musicianReview.retryQueued'),
  )
}

async function decide(item: MusicianReviewItem, decision: MusicianReviewDecision) {
  await runAction(
    `decision-${item.album.id}`,
    () => reviews.decide(item.album.id, decision),
    t('musicianReview.reviewSaved'),
  )
}

async function reopen(item: MusicianReviewItem) {
  await runAction(
    `reopen-${item.album.id}`,
    () => reviews.reopen(item.album.id),
    t('musicianReview.reopened'),
  )
}

async function runAction(key: string, action: () => Promise<unknown>, success: string) {
  actionKey.value = key
  try {
    await action()
    showNotice(success)
    await load()
  } catch (cause) {
    showNotice(cause instanceof Error ? cause.message : t('musicianReview.actionFailed'))
  } finally {
    actionKey.value = null
  }
}

function albumRoute(item: MusicianReviewItem) {
  return {
    name: 'album-detail',
    params: { id: item.album.id },
    query: {
      backTo: 'musician-review',
      reviewStatus: status.value,
      ...(page.value > 1 ? { reviewPage: String(page.value) } : {}),
    },
  }
}

function reviewStatus(value: unknown): MusicianReviewStatus {
  return value === 'failed' || value === 'reviewed' ? value : 'ambiguous'
}

function reviewPage(value: unknown) {
  const parsed = typeof value === 'string' || typeof value === 'number' ? Number(value) : NaN
  return Number.isInteger(parsed) && parsed > 0 ? parsed : 1
}

function syncRoute() {
  if (route.name !== 'musician-review') return

  const query = {
    ...(status.value !== 'ambiguous' ? { status: status.value } : {}),
    ...(page.value > 1 ? { page: String(page.value) } : {}),
  }
  if (JSON.stringify(route.query) === JSON.stringify(query)) return

  void router.replace({ name: 'musician-review', query })
}

function decisionLabel(decision?: MusicianReviewDecision) {
  return decision ? t(`musicianReview.decisions.${decision}`) : ''
}

function errorLabel(errorCode?: string | null) {
  return errorCode ? t('musicianReview.providerError', { code: errorCode }) : t('musicianReview.unknownError')
}

function showNotice(message: string) {
  notice.value = message
  noticeVisible.value = true
}
</script>

<template>
  <v-btn class="mb-4" prepend-icon="mdi-arrow-left" :to="{ name: 'musicians' }" variant="text">
    {{ t('musicianReview.back') }}
  </v-btn>

  <PageHeader
    :title="t('musicianReview.title')"
    :count="reviews.loading || reviews.error ? undefined : reviews.results.total"
    :description="t('musicianReview.description')"
    icon="mdi-account-question-outline"
  />

  <v-card border class="mb-6" rounded="xl">
    <v-tabs v-model="status" color="primary" grow>
      <v-tab prepend-icon="mdi-source-branch" value="ambiguous">
        {{ t('musicianReview.ambiguous') }} ({{ reviews.results.counts.ambiguous }})
      </v-tab>
      <v-tab prepend-icon="mdi-alert-circle-outline" value="failed">
        {{ t('musicianReview.failed') }} ({{ reviews.results.counts.failed }})
      </v-tab>
      <v-tab prepend-icon="mdi-check-circle-outline" value="reviewed">
        {{ t('musicianReview.reviewed') }} ({{ reviews.results.counts.reviewed }})
      </v-tab>
    </v-tabs>
  </v-card>

  <CatalogPagination
    class="mb-4"
    :model-value="page"
    :length="reviews.results.lastPage"
    @update:model-value="page = $event"
  />
  <v-alert v-if="reviews.error" class="mb-4" type="error" variant="tonal">{{ reviews.error }}</v-alert>
  <v-skeleton-loader v-else-if="reviews.loading" type="card@3" />
  <div v-else-if="reviews.results.items.length" class="d-flex flex-column ga-4">
    <v-card v-for="item in reviews.results.items" :key="item.album.id" border rounded="xl">
      <div class="review-album-header pa-4">
        <v-avatar color="surface-bright" rounded="lg" size="72">
          <v-img v-if="item.album.artworkThumbnailUrl" :src="item.album.artworkThumbnailUrl" cover />
          <v-icon v-else icon="mdi-album" size="32" />
        </v-avatar>
        <div class="min-width-0">
          <RouterLink class="review-album-link text-h6 font-weight-bold" :to="albumRoute(item)">
            {{ item.album.title }}
          </RouterLink>
          <div class="text-body-2 text-medium-emphasis">
            {{ item.album.primaryArtist?.name ?? t('catalog.unknownArtist') }}
            <template v-if="item.album.originalReleaseYear"> · {{ item.album.originalReleaseYear }}</template>
          </div>
          <div class="text-caption text-medium-emphasis">
            {{ item.album.libraryRoot.name }} · {{ t('albums.trackCount', { count: item.album.trackCount }) }}
            <template v-if="item.fetchedAt"> · {{ formatDateTime(item.fetchedAt, locale) }}</template>
          </div>
        </div>
        <v-chip
          class="review-status"
          :color="item.status === 'error' ? 'warning' : 'primary'"
          size="small"
          variant="tonal"
        >
          {{ item.status === 'error' ? t('musicianReview.failed') : t('musicianReview.ambiguous') }}
        </v-chip>
      </div>

      <v-divider />
      <v-card-text v-if="status === 'ambiguous'">
        <p class="text-body-2 text-medium-emphasis mb-4">{{ t('musicianReview.chooseReleaseHint') }}</p>
        <MusicBrainzReleaseChooser
          v-if="item.candidateReleases.length"
          :model-value="selectedReleases[item.album.id] ?? null"
          :candidates="item.candidateReleases"
          @update:model-value="selectedReleases[item.album.id] = $event"
        />
        <v-alert v-else type="info" variant="tonal">{{ t('musicianReview.noCandidates') }}</v-alert>
      </v-card-text>
      <v-card-text v-else-if="status === 'failed'">
        <v-alert type="warning" variant="tonal">
          <strong>{{ errorLabel(item.errorCode) }}</strong>
          <div v-if="item.failureCount > 1" class="text-caption mt-1">
            {{ t('musicianReview.failureCount', { count: item.failureCount }) }}
          </div>
          <div v-if="item.retryAfter" class="text-caption mt-1">
            {{ t('musicianReview.retryAfter', { date: formatDateTime(item.retryAfter, locale) }) }}
          </div>
        </v-alert>
      </v-card-text>
      <v-card-text v-else>
        <v-alert color="success" type="success" variant="tonal">
          {{ decisionLabel(item.review?.decision) }}
          <template v-if="item.review?.reviewedAt">
            · {{ formatDateTime(item.review.reviewedAt, locale) }}
          </template>
        </v-alert>
      </v-card-text>

      <v-card-actions class="review-actions px-4 pb-4">
        <v-btn
          v-if="status === 'ambiguous' && item.candidateReleases.length"
          color="primary"
          :disabled="!selectedReleases[item.album.id]"
          :loading="actionKey === `release-${item.album.id}`"
          prepend-icon="mdi-source-branch"
          variant="flat"
          @click="void selectRelease(item)"
        >
          {{ t('musicianReview.useRelease') }}
        </v-btn>
        <v-btn
          v-if="status === 'failed'"
          :loading="actionKey === `retry-${item.album.id}`"
          prepend-icon="mdi-refresh"
          variant="tonal"
          @click="void retry(item)"
        >
          {{ t('musicianReview.retry') }}
        </v-btn>
        <MusicianCreditsEditor :album-id="item.album.id" @updated="showNotice(t('musicianReview.manualUpdated'))" />
        <v-menu v-if="status !== 'reviewed'">
          <template #activator="{ props }">
            <v-btn v-bind="props" append-icon="mdi-menu-down" variant="text">
              {{ t('musicianReview.markReviewed') }}
            </v-btn>
          </template>
          <v-list>
            <v-list-item
              prepend-icon="mdi-link-variant-off"
              :title="t('musicianReview.noSuitableMatch')"
              @click="void decide(item, 'no_suitable_match')"
            />
            <v-list-item
              prepend-icon="mdi-eye-off-outline"
              :title="t('musicianReview.dismiss')"
              @click="void decide(item, 'dismissed')"
            />
          </v-list>
        </v-menu>
        <v-btn
          v-else
          :loading="actionKey === `reopen-${item.album.id}`"
          prepend-icon="mdi-restore"
          variant="tonal"
          @click="void reopen(item)"
        >
          {{ t('musicianReview.reopen') }}
        </v-btn>
        <v-spacer />
        <v-btn append-icon="mdi-arrow-right" :to="albumRoute(item)" variant="text">
          {{ t('musicianReview.openAlbum') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </div>
  <EmptyCatalogState
    v-else
    :description="t(`musicianReview.emptyDescriptions.${status}`)"
    icon="mdi-account-check-outline"
    :title="t('musicianReview.emptyTitle')"
  />
  <CatalogPagination
    class="mt-4"
    :model-value="page"
    :length="reviews.results.lastPage"
    @update:model-value="page = $event"
  />

  <v-snackbar v-model="noticeVisible" :timeout="6000">{{ notice }}</v-snackbar>
</template>

<style scoped>
.review-album-header {
  align-items: center;
  display: grid;
  gap: 1rem;
  grid-template-columns: auto minmax(0, 1fr) auto;
}

.review-album-link {
  color: inherit;
  text-decoration: none;
}

.review-album-link:hover {
  color: rgb(var(--v-theme-primary));
  text-decoration: underline;
}

.review-actions {
  flex-wrap: wrap;
  gap: 0.5rem;
}

.min-width-0 {
  min-width: 0;
}

@media (max-width: 600px) {
  .review-album-header {
    align-items: start;
    grid-template-columns: auto minmax(0, 1fr);
  }

  .review-status {
    grid-column: 1 / -1;
    justify-self: start;
  }
}
</style>

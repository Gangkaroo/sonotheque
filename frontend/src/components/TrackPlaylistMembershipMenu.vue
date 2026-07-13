<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import TooltipIconButton from '@/components/TooltipIconButton.vue'
import { usePlaylistsStore } from '@/stores/playlists'
import type { TrackPlaylistMembership } from '@/stores/playlists'

const props = withDefaults(defineProps<{
  trackId: number
  iconOnly?: boolean
}>(), {
  iconOnly: false,
})

const { t } = useI18n()
const playlists = usePlaylistsStore()
const memberships = computed(() => playlists.membershipsForTrack(props.trackId))
const buttonText = computed(() => t('playlists.inPlaylists', { count: memberships.value.length }))

function membershipSubtitle(membership: TrackPlaylistMembership) {
  const folder = membership.folder?.name ?? t('playlists.noFolder')
  if (membership.occurrenceCount === 1) return folder

  return `${folder} · ${t('playlists.trackOccurrences', { count: membership.occurrenceCount })}`
}
</script>

<template>
  <v-menu v-if="memberships.length" location="bottom end">
    <template #activator="{ props: menuProps }">
      <TooltipIconButton
        v-if="iconOnly"
        v-bind="menuProps"
        :text="buttonText"
        :aria-label="buttonText"
        color="primary"
        density="comfortable"
        icon="mdi-playlist-check"
        variant="text"
      />
      <v-btn
        v-else
        v-bind="menuProps"
        color="primary"
        prepend-icon="mdi-playlist-check"
        variant="tonal"
      >
        {{ buttonText }}
      </v-btn>
    </template>

    <v-list density="compact" min-width="280">
      <v-list-subheader>{{ t('playlists.playlistMemberships') }}</v-list-subheader>
      <v-list-item
        v-for="membership in memberships"
        :key="membership.id"
        prepend-icon="mdi-playlist-music-outline"
        :subtitle="membershipSubtitle(membership)"
        :title="membership.name"
        :to="{
          name: 'playlist-detail',
          params: { id: membership.id },
          query: { playlistItem: membership.firstItemId },
        }"
      />
    </v-list>
  </v-menu>
</template>

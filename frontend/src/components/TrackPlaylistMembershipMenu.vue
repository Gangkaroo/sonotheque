<script setup lang="ts">
import { computed, ref } from 'vue'
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
const emit = defineEmits<{
  addToPlaylist: []
}>()

const { t } = useI18n()
const playlists = usePlaylistsStore()
const memberships = computed(() => playlists.membershipsForTrack(props.trackId))
const buttonText = computed(() => t('playlists.inPlaylists', { count: memberships.value.length }))
const removingPlaylistId = ref<number | null>(null)
const removeError = ref<string | null>(null)
const removeErrorVisible = ref(false)

function membershipSubtitle(membership: TrackPlaylistMembership) {
  const folder = membership.folder?.name ?? t('playlists.noFolder')
  if (membership.occurrenceCount === 1) return folder

  return `${folder} · ${t('playlists.trackOccurrences', { count: membership.occurrenceCount })}`
}

async function removeMembership(membership: TrackPlaylistMembership) {
  removingPlaylistId.value = membership.id
  removeError.value = null
  try {
    await playlists.removeTrackFromPlaylist(membership.id, props.trackId)
  } catch (cause) {
    removeError.value = cause instanceof Error
      ? cause.message
      : t('playlists.removeTrackFromPlaylistFailed')
    removeErrorVisible.value = true
  } finally {
    removingPlaylistId.value = null
  }
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
      >
        <template #append>
          <TooltipIconButton
            :aria-label="t('playlists.removeTrackFromPlaylist', { name: membership.name })"
            color="error"
            density="compact"
            icon="mdi-delete-outline"
            :loading="removingPlaylistId === membership.id"
            size="small"
            :text="t('playlists.removeTrackFromPlaylist', { name: membership.name })"
            variant="text"
            @click.stop.prevent="void removeMembership(membership)"
          />
        </template>
      </v-list-item>
      <v-divider />
      <v-list-item
        prepend-icon="mdi-playlist-music"
        :title="t('playlists.addTrackToPlaylist')"
        @click="emit('addToPlaylist')"
      />
    </v-list>
  </v-menu>
  <TooltipIconButton
    v-else-if="iconOnly"
    :text="t('playlists.addTrackToPlaylist')"
    :aria-label="t('playlists.addTrackToPlaylist')"
    density="comfortable"
    icon="mdi-playlist-music"
    variant="text"
    @click="emit('addToPlaylist')"
  />
  <v-btn
    v-else
    color="primary"
    prepend-icon="mdi-playlist-music"
    variant="tonal"
    @click="emit('addToPlaylist')"
  >
    {{ t('playlists.addTrackToPlaylist') }}
  </v-btn>

  <v-snackbar v-model="removeErrorVisible" color="error" timeout="4000">
    {{ removeError }}
  </v-snackbar>
</template>

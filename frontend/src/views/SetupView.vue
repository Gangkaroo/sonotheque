<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import LastFmSettings from '@/components/LastFmSettings.vue'
import LibraryRootDialog from '@/components/LibraryRootDialog.vue'
import MetadataSettings from '@/components/MetadataSettings.vue'
import OnlineEnrichmentSettings from '@/components/OnlineEnrichmentSettings.vue'
import SystemHealthSettings from '@/components/SystemHealthSettings.vue'
import { useFirstRunSetupStore } from '@/stores/firstRunSetup'
import { useLibraryRootsStore } from '@/stores/libraryRoots'
import { useScanRunsStore } from '@/stores/scanRuns'

const { t } = useI18n()
const router = useRouter()
const setup = useFirstRunSetupStore()
const libraryRoots = useLibraryRootsStore()
const scanRuns = useScanRunsStore()
const currentStep = ref(1)
const rootDialog = ref(false)
const initializing = ref(true)
let pollTimer: ReturnType<typeof setTimeout> | null = null

const steps = computed(() => [
  { value: 1, icon: 'mdi-heart-pulse', title: t('setup.runtime') },
  { value: 2, icon: 'mdi-folder-music-outline', title: t('setup.library') },
  { value: 3, icon: 'mdi-tag-multiple-outline', title: t('setup.metadata') },
  { value: 4, icon: 'mdi-connection', title: t('setup.connections') },
  { value: 5, icon: 'mdi-database-import-outline', title: t('setup.firstScan') },
])

onMounted(async () => {
  try {
    await Promise.all([setup.load(), libraryRoots.load(), scanRuns.load()])
    currentStep.value = setup.status?.step ?? 1
    schedulePolling()
  } finally {
    initializing.value = false
  }
})

onUnmounted(() => {
  if (pollTimer) clearTimeout(pollTimer)
})

async function selectStep(step: number) {
  if (initializing.value) return

  currentStep.value = step
  await setup.update({ step })
}

async function next() {
  if (currentStep.value < 5) await selectStep(currentStep.value + 1)
}

async function previous() {
  if (currentStep.value > 1) await selectStep(currentStep.value - 1)
}

async function rootSaved() {
  await setup.load()
}

async function startScan(rootId: number) {
  await scanRuns.start(rootId)
  schedulePolling()
}

function schedulePolling() {
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = scanRuns.hasActiveScans ? setTimeout(pollScans, 2000) : null
}

async function pollScans() {
  await scanRuns.load({ silent: true })
  schedulePolling()
}

function scanFor(rootId: number) {
  return scanRuns.latestForRoot(rootId)
}

function scanProgress(rootId: number) {
  const scan = scanFor(rootId)
  if (!scan?.filesDiscovered) return 0
  return Math.min(100, Math.round((scan.filesProcessed / scan.filesDiscovered) * 100))
}

async function finish() {
  await setup.update({ step: 5, completed: true })
  await router.replace('/')
}
</script>

<template>
  <div class="setup-view mx-auto">
    <div class="text-center mb-8">
      <v-icon color="primary" icon="mdi-waveform" size="52" />
      <h1 class="text-h4 font-weight-bold mt-3">{{ t('setup.title') }}</h1>
      <p class="text-body-1 text-medium-emphasis mt-2">{{ t('setup.description') }}</p>
    </div>

    <v-alert v-if="setup.error" class="mb-5" type="error" variant="tonal">{{ setup.error }}</v-alert>
    <v-skeleton-loader v-if="setup.loading && !setup.status" type="article" />

    <template v-else>
      <div class="setup-steps d-flex mb-6">
        <button
          v-for="step in steps"
          :key="step.value"
          class="setup-step flex-grow-1 pa-3"
          :class="{ 'setup-step--active': currentStep === step.value }"
          :disabled="initializing"
          type="button"
          @click="selectStep(step.value)"
        >
          <v-icon :color="currentStep === step.value ? 'primary' : undefined" :icon="step.icon" />
          <span class="d-none d-sm-inline ml-2">{{ step.title }}</span>
        </button>
      </div>

      <section v-if="currentStep === 1">
        <v-alert type="info" variant="tonal" class="mb-2">{{ t('setup.runtimeHint') }}</v-alert>
        <SystemHealthSettings />
      </section>

      <section v-else-if="currentStep === 2">
        <v-card border rounded="xl">
          <v-card-item class="pa-6" prepend-icon="mdi-folder-music-outline">
            <v-card-title>{{ t('setup.libraryTitle') }}</v-card-title>
            <v-card-subtitle>{{ t('setup.libraryHint') }}</v-card-subtitle>
            <template #append>
              <v-btn color="primary" prepend-icon="mdi-folder-plus-outline" variant="flat" @click="rootDialog = true">
                {{ t('settings.addRoot') }}
              </v-btn>
            </template>
          </v-card-item>
          <v-card-text class="pa-6 pt-0">
            <v-alert v-if="!libraryRoots.hasRoots" type="warning" variant="tonal">
              {{ t('setup.rootRequired') }}
            </v-alert>
            <v-list v-else lines="two">
              <v-list-item
                v-for="root in libraryRoots.roots"
                :key="root.id"
                prepend-icon="mdi-harddisk"
                :subtitle="root.path"
                :title="root.name"
              />
            </v-list>
          </v-card-text>
        </v-card>
      </section>

      <section v-else-if="currentStep === 3">
        <v-alert type="info" variant="tonal">{{ t('setup.metadataHint') }}</v-alert>
        <MetadataSettings />
      </section>

      <section v-else-if="currentStep === 4">
        <v-alert type="info" variant="tonal">{{ t('setup.connectionsHint') }}</v-alert>
        <LastFmSettings />
        <OnlineEnrichmentSettings />
      </section>

      <section v-else>
        <v-card border rounded="xl">
          <v-card-item class="pa-6" prepend-icon="mdi-database-import-outline">
            <v-card-title>{{ t('setup.firstScanTitle') }}</v-card-title>
            <v-card-subtitle>{{ t('setup.firstScanHint') }}</v-card-subtitle>
          </v-card-item>
          <v-card-text class="pa-6 pt-0">
            <v-alert class="mb-4" icon="mdi-counter" variant="tonal">{{ t('setup.countingExplanation') }}</v-alert>
            <v-list>
              <v-list-item v-for="root in libraryRoots.roots" :key="root.id" prepend-icon="mdi-harddisk">
                <v-list-item-title>{{ root.name }}</v-list-item-title>
                <v-list-item-subtitle>{{ root.path }}</v-list-item-subtitle>
                <v-progress-linear
                  v-if="scanFor(root.id) && ['pending', 'running'].includes(scanFor(root.id)!.status)"
                  class="mt-3"
                  color="primary"
                  :indeterminate="scanFor(root.id)?.summary?.phase === 'counting'"
                  :model-value="scanProgress(root.id)"
                  rounded
                />
                <template #append>
                  <v-btn
                    :disabled="Boolean(scanFor(root.id) && ['pending', 'running'].includes(scanFor(root.id)!.status))"
                    :loading="scanRuns.startingRootId === root.id"
                    prepend-icon="mdi-database-sync-outline"
                    variant="tonal"
                    @click="startScan(root.id)"
                  >
                    {{ t('settings.startScan') }}
                  </v-btn>
                </template>
              </v-list-item>
            </v-list>
          </v-card-text>
        </v-card>
      </section>

      <div class="d-flex flex-wrap justify-space-between ga-3 mt-6">
        <v-btn :disabled="initializing || currentStep === 1" prepend-icon="mdi-chevron-left" variant="text" @click="previous">
          {{ t('setup.back') }}
        </v-btn>
        <v-btn
          v-if="currentStep < 5"
          color="primary"
          :disabled="initializing || (currentStep === 2 && !libraryRoots.hasRoots)"
          :loading="setup.saving"
          append-icon="mdi-chevron-right"
          variant="flat"
          @click="next"
        >
          {{ t('setup.continue') }}
        </v-btn>
        <v-btn
          v-else
          color="primary"
          :disabled="initializing || !libraryRoots.hasRoots"
          :loading="setup.saving"
          append-icon="mdi-check"
          variant="flat"
          @click="finish"
        >
          {{ t('setup.finish') }}
        </v-btn>
      </div>
    </template>

    <LibraryRootDialog v-model="rootDialog" @saved="rootSaved" />
  </div>
</template>

<style scoped>
.setup-view {
  max-width: 1080px;
}

.setup-steps {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 16px;
  overflow: hidden;
}

.setup-step {
  background: transparent;
  border: 0;
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
}

.setup-step + .setup-step {
  border-left: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.setup-step--active {
  background: rgba(var(--v-theme-primary), 0.1);
  font-weight: 700;
}
</style>

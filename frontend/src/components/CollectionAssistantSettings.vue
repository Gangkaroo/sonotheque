<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { useCollectionAssistantSettingsStore } from '@/stores/collectionAssistantSettings'

const { t } = useI18n()
const assistant = useCollectionAssistantSettingsStore()
const enabled = ref(false)
const model = ref<string | null>(null)

const modelItems = computed(() => assistant.models.map((item) => ({
  title: item.name,
  value: item.name,
  subtitle: [item.parameterSize, item.quantization].filter(Boolean).join(' · '),
})))
const discoveryMessage = computed(() => assistant.discoveryStatus === 'error'
  ? providerError(assistant.discoveryErrorCode)
  : assistant.discoveryStatus === 'available' && assistant.models.length === 0
    ? t('settings.collectionAssistantNoModels')
    : null)
const testMessage = computed(() => {
  if (!assistant.testResult) return null
  return assistant.testResult.status === 'available'
    ? t('settings.collectionAssistantTestSuccessful', { model: assistant.testResult.model })
    : providerError(assistant.testResult.errorCode)
})

onMounted(async () => {
  await assistant.load()
  enabled.value = assistant.settings.enabled
  model.value = assistant.settings.model
})

async function save() {
  await assistant.save({ enabled: enabled.value, model: normalizedModel() })
  enabled.value = assistant.settings.enabled
  model.value = assistant.settings.model
}

async function testConnection() {
  await assistant.test(normalizedModel())
}

function normalizedModel() {
  return model.value?.trim() || null
}

function providerError(errorCode: string | null) {
  const key = `settings.collectionAssistantErrors.${errorCode ?? 'provider_error'}`
  return t(key, {}, { default: t('settings.collectionAssistantErrors.provider_error') })
}
</script>

<template>
  <v-card border class="mt-6" rounded="xl">
    <v-card-item class="pa-6 pb-2">
      <template #prepend>
        <v-avatar color="primary" variant="tonal">
          <v-icon icon="mdi-message-processing-outline" />
        </v-avatar>
      </template>
      <v-card-title>{{ t('settings.collectionAssistant') }}</v-card-title>
      <v-card-subtitle>{{ t('settings.collectionAssistantDescription') }}</v-card-subtitle>
    </v-card-item>

    <v-card-text class="pa-6 pt-4">
      <v-skeleton-loader v-if="assistant.loading" type="list-item-two-line, list-item-two-line" />
      <template v-else>
        <v-alert class="mb-5" type="info" variant="tonal">
          {{ t('settings.collectionAssistantOptIn') }}
        </v-alert>
        <v-alert v-if="assistant.error" class="mb-5" type="error" variant="tonal">
          {{ assistant.error }}
        </v-alert>

        <v-switch
          v-model="enabled"
          color="primary"
          :label="t('settings.collectionAssistantEnabled')"
          :hint="t('settings.collectionAssistantEnabledHint')"
          persistent-hint
        />

        <div class="assistant-provider-grid mt-5">
          <v-text-field
            :model-value="assistant.settings.provider === 'ollama' ? 'Ollama' : assistant.settings.provider"
            :label="t('settings.collectionAssistantProvider')"
            prepend-inner-icon="mdi-server-outline"
            readonly
            variant="outlined"
          />
          <v-text-field
            :model-value="assistant.settings.endpoint"
            :label="t('settings.collectionAssistantEndpoint')"
            :hint="t('settings.collectionAssistantEndpointHint')"
            persistent-hint
            readonly
            variant="outlined"
          />
        </div>

        <v-combobox
          v-model="model"
          class="mt-3"
          clearable
          :items="modelItems"
          item-title="title"
          item-value="value"
          :label="t('settings.collectionAssistantModel')"
          :hint="t('settings.collectionAssistantModelHint', { model: assistant.settings.recommendedModel })"
          persistent-hint
          prepend-inner-icon="mdi-creation-outline"
          :return-object="false"
          variant="outlined"
        >
          <template #item="{ props, item }">
            <v-list-item v-bind="props" :subtitle="item.subtitle || undefined" />
          </template>
        </v-combobox>

        <v-alert
          v-if="discoveryMessage"
          class="mt-4"
          :type="assistant.discoveryStatus === 'error' ? 'error' : 'warning'"
          variant="tonal"
        >
          {{ discoveryMessage }}
        </v-alert>
        <v-alert
          v-if="testMessage"
          class="mt-4"
          :type="assistant.testResult?.status === 'available' ? 'success' : 'error'"
          variant="tonal"
        >
          {{ testMessage }}
        </v-alert>

        <div class="d-flex flex-wrap ga-3 mt-5">
          <v-btn
            prepend-icon="mdi-database-search-outline"
            :loading="assistant.discovering"
            variant="tonal"
            @click="assistant.discoverModels"
          >
            {{ t('settings.collectionAssistantDiscoverModels') }}
          </v-btn>
          <v-btn
            :disabled="!normalizedModel()"
            prepend-icon="mdi-connection"
            :loading="assistant.testing"
            variant="tonal"
            @click="testConnection"
          >
            {{ t('settings.collectionAssistantTest') }}
          </v-btn>
          <v-btn
            color="primary"
            :disabled="enabled && !normalizedModel()"
            prepend-icon="mdi-content-save-outline"
            :loading="assistant.saving"
            variant="flat"
            @click="save"
          >
            {{ t('settings.collectionAssistantSave') }}
          </v-btn>
        </div>
      </template>
    </v-card-text>
  </v-card>
</template>

<style scoped>
.assistant-provider-grid {
  display: grid;
  gap: 16px;
  grid-template-columns: minmax(180px, 0.35fr) minmax(260px, 1fr);
}

@media (max-width: 700px) {
  .assistant-provider-grid {
    grid-template-columns: 1fr;
  }
}
</style>

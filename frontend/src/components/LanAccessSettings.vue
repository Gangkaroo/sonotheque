<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { apiRequest } from '@/api/client'
import { useAdminAccessStore } from '@/stores/adminAccess'

const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const adminAccess = useAdminAccessStore()
const token = ref(adminAccess.token)
const remember = ref(adminAccess.remember)
const visible = ref(false)
const saving = ref(false)
const saved = ref(false)
const error = ref<string | null>(null)

async function save() {
  const candidate = token.value.trim()
  if (!candidate) return

  saving.value = true
  saved.value = false
  error.value = null
  try {
    await apiRequest<{ authorized: boolean }>('/settings/access', {
      headers: { 'X-Music-Library-Admin-Token': candidate },
    })
    adminAccess.save(candidate, remember.value)
    saved.value = true
    emit('changed')
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('settings.adminTokenInvalid')
  } finally {
    saving.value = false
  }
}

function clear() {
  adminAccess.clear()
  token.value = ''
  remember.value = false
  saved.value = false
  error.value = null
  emit('changed')
}
</script>

<template>
  <v-card border rounded="xl" class="mt-6">
    <v-card-item class="pa-6 pb-2" prepend-icon="mdi-shield-lock-outline">
      <v-card-title>{{ t('settings.lanAccess') }}</v-card-title>
      <v-card-subtitle>{{ t('settings.lanAccessDescription') }}</v-card-subtitle>
    </v-card-item>
    <v-card-text class="pa-6 pt-4">
      <v-alert class="mb-4" type="info" variant="tonal">
        {{ t('settings.adminTokenHint') }}
      </v-alert>
      <v-alert v-if="error" class="mb-4" type="error" variant="tonal">{{ error }}</v-alert>
      <v-alert v-if="saved" class="mb-4" type="success" variant="tonal">
        {{ t('settings.adminTokenSaved') }}
      </v-alert>
      <v-text-field
        v-model="token"
        autocomplete="off"
        :append-inner-icon="visible ? 'mdi-eye-off-outline' : 'mdi-eye-outline'"
        :label="t('settings.adminToken')"
        :type="visible ? 'text' : 'password'"
        @click:append-inner="visible = !visible"
        @keyup.enter="save"
      />
      <v-checkbox
        v-model="remember"
        color="primary"
        :label="t('settings.rememberAdminToken')"
      />
      <div class="d-flex flex-wrap justify-end ga-2">
        <v-btn
          :disabled="!adminAccess.hasToken"
          prepend-icon="mdi-delete-outline"
          variant="text"
          @click="clear"
        >
          {{ t('settings.clearAdminToken') }}
        </v-btn>
        <v-btn
          color="primary"
          :disabled="!token.trim()"
          :loading="saving"
          prepend-icon="mdi-check-decagram-outline"
          variant="flat"
          @click="save"
        >
          {{ t('settings.verifyAdminToken') }}
        </v-btn>
      </div>
    </v-card-text>
  </v-card>
</template>

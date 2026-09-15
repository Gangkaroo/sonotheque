import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

import { apiRequest } from '@/api/client'
import SystemBackupSettings from '@/components/SystemBackupSettings.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'

vi.mock('@/api/client', () => ({
  apiRequest: vi.fn(),
}))

describe('SystemBackupSettings', () => {
  beforeAll(() => {
    vi.stubGlobal('ResizeObserver', class {
      observe() {}
      unobserve() {}
      disconnect() {}
    })
  })

  beforeEach(() => {
    i18n.global.locale.value = 'en'
    vi.mocked(apiRequest).mockReset()
  })

  it('starts a backup for the selected folder', async () => {
    vi.mocked(apiRequest).mockResolvedValue({
      id: 'backup-1',
      type: 'backup',
      status: 'completed',
      progress: 100,
      phase: null,
      message: null,
      archiveName: 'sonotheque-backup.zip',
      createdAt: '2026-09-15T12:00:00Z',
      startedAt: '2026-09-15T12:00:00Z',
      finishedAt: '2026-09-15T12:01:00Z',
    })
    const folderBrowserStub = defineComponent({
      props: {
        modelValue: Boolean,
        systemBackupFiles: Boolean,
      },
      emits: ['select', 'update:modelValue'],
      template: '<button class="folder-picker" @click="$emit(\'select\', \'C:/Backups\')">Pick</button>',
    })
    const wrapper = mount(SystemBackupSettings, {
      props: {
        backup: {
          available: false,
          operation: null,
          status: null,
          mode: null,
          completedAt: null,
          bundleName: null,
          bytes: null,
        },
        environment: 'local',
      },
      global: {
        plugins: [i18n, vuetify],
        stubs: { FolderBrowserDialog: folderBrowserStub },
      },
    })

    await wrapper.findAll('.folder-picker')[0]?.trigger('click')

    expect(apiRequest).toHaveBeenCalledWith('/settings/system-backups', {
      method: 'POST',
      body: JSON.stringify({ destination: 'C:/Backups' }),
    })
  })
})

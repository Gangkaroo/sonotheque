import { createPinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { VApp, VCombobox } from 'vuetify/components'

import CollectionAssistantSettings from '@/components/CollectionAssistantSettings.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'

describe('CollectionAssistantSettings', () => {
  beforeAll(() => {
    vi.stubGlobal('ResizeObserver', class {
      observe() {}
      unobserve() {}
      disconnect() {}
    })
  })

  beforeEach(() => {
    i18n.global.locale.value = 'en'
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      enabled: false,
      provider: 'ollama',
      model: null,
      endpoint: 'http://127.0.0.1:11434',
      recommendedModel: 'qwen3:8b',
    }), { status: 200 })))
  })

  it('stores a discovered model as its name and enables model testing', async () => {
    const host = defineComponent({
      components: { CollectionAssistantSettings, VApp },
      template: '<VApp><CollectionAssistantSettings /></VApp>',
    })
    const wrapper = mount(host, {
      global: { plugins: [createPinia(), i18n, vuetify] },
    })
    await flushPromises()

    const combobox = wrapper.findComponent(VCombobox)
    expect(combobox.props('returnObject')).toBe(false)

    combobox.vm.$emit('update:modelValue', 'qwen3:8b')
    await wrapper.vm.$nextTick()

    const testButton = wrapper.findAll('button')
      .find((button) => button.text().includes('Test model'))
    expect(testButton?.attributes('disabled')).toBeUndefined()
  })
})

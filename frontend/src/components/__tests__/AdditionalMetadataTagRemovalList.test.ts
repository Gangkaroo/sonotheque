import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

import AdditionalMetadataTagRemovalList from '@/components/AdditionalMetadataTagRemovalList.vue'
import { i18n } from '@/plugins/i18n'
import { vuetify } from '@/plugins/vuetify'

describe('AdditionalMetadataTagRemovalList', () => {
  beforeEach(() => {
    i18n.global.locale.value = 'en'
  })

  it('disables first-played removal when synchronized and leaves unrelated tags editable', async () => {
    const wrapper = mount(AdditionalMetadataTagRemovalList, {
      props: {
        modelValue: [],
        title: 'Additional tags',
        hint: 'Select tags to remove',
        tags: [{
          key: 'TXXX:FIRST_PLAYED_TIMESTAMP',
          frameId: 'TXXX',
          name: 'first_played_timestamp',
          values: ['130533609459477217'],
          playbackStatistic: true,
          rating: false,
          protectedFromRemoval: true,
        }, {
          key: 'TXXX:SOURCE',
          frameId: 'TXXX',
          name: 'SOURCE',
          values: ['CD'],
          playbackStatistic: false,
          rating: false,
          protectedFromRemoval: false,
        }],
      },
      global: { plugins: [i18n, vuetify] },
    })
    const checkboxes = wrapper.findAll<HTMLInputElement>('input[type="checkbox"]')
    expect(checkboxes[0]!.element.disabled).toBe(true)
    expect(checkboxes[1]!.element.disabled).toBe(false)
    await checkboxes[1]!.setValue(true)
    expect(wrapper.emitted('update:modelValue')).toEqual([[['TXXX:SOURCE']]])

    await wrapper.setProps({ tags: wrapper.props('tags').map(tag => ({
      ...tag, protectedFromRemoval: false,
    })) })
    expect(checkboxes[0]!.element.disabled).toBe(false)
    wrapper.unmount()
  })
})

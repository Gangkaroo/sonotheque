import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import AssistantMarkdown from '@/components/AssistantMarkdown.vue'

describe('AssistantMarkdown', () => {
  it('renders common Markdown formatting', () => {
    const wrapper = mount(AssistantMarkdown, {
      props: {
        content: '**Bold** and *italic*\n\n- First\n- Second',
      },
    })

    expect(wrapper.get('strong').text()).toBe('Bold')
    expect(wrapper.get('em').text()).toBe('italic')
    expect(wrapper.findAll('li').map((item) => item.text())).toEqual(['First', 'Second'])
  })

  it('sanitizes links and opens safe links in a new tab', () => {
    const wrapper = mount(AssistantMarkdown, {
      props: {
        content: '[Safe](https://example.com) [Unsafe](javascript:alert(1))',
      },
    })
    const links = wrapper.findAll('a')

    expect(links[0]?.attributes()).toMatchObject({
      href: 'https://example.com',
      target: '_blank',
      rel: 'noopener noreferrer',
    })
    expect(links[1]?.attributes('href')).toBeUndefined()
  })
})

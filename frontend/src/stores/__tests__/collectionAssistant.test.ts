import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useCollectionAssistantStore } from '@/stores/collectionAssistant'

describe('collection assistant store', () => {
  beforeEach(() => {
    window.localStorage.clear()
    setActivePinia(createPinia())
    vi.unstubAllGlobals()
  })

  it('keeps conversations separate for each library scope', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(response('First answer'))
      .mockResolvedValueOnce(response('Second answer'))
    vi.stubGlobal('fetch', fetchMock)
    const assistant = useCollectionAssistantStore()

    await assistant.ask('First question', 'root:1', 1)
    await assistant.ask('Second question', 'root:2', 2)

    expect(assistant.messagesFor('root:1').map((message) => message.content))
      .toEqual(['First question', 'First answer'])
    expect(assistant.messagesFor('root:2').map((message) => message.content))
      .toEqual(['Second question', 'Second answer'])
    expect(JSON.parse(fetchMock.mock.calls[1]?.[1]?.body as string)).toMatchObject({
      libraryRoot: 2,
      history: [],
    })
  })

  it('sends bounded local history with a follow-up question', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(response('First answer'))
      .mockResolvedValueOnce(response('Follow-up answer'))
    vi.stubGlobal('fetch', fetchMock)
    const assistant = useCollectionAssistantStore()

    await assistant.ask('First question', 'all', null)
    await assistant.ask('And which albums?', 'all', null)

    expect(JSON.parse(fetchMock.mock.calls[1]?.[1]?.body as string)).toMatchObject({
      question: 'And which albums?',
      history: [
        { role: 'user', content: 'First question' },
        { role: 'assistant', content: 'First answer' },
      ],
    })
  })
})

function response(answer: string) {
  return new Response(JSON.stringify({ answer, toolsUsed: [], references: [] }), { status: 200 })
}

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

  it('stores playback previews and requires an explicit local decision', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
      answer: 'I prepared a playback preview.',
      toolsUsed: ['find_similar_tracks'],
      references: [],
      action: {
        type: 'track_queue',
        mode: 'play',
        scope: { id: null, name: 'All library roots' },
        tracks: [{
          id: 42,
          title: 'Verified track',
          streamUrl: '/api/tracks/42/stream',
          album: null,
          artists: [],
          playStatistics: { playCount: 0 },
        }],
      },
    }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    const assistant = useCollectionAssistantStore()

    await assistant.ask('Play something similar.', 'all', null)
    const message = assistant.messagesFor('all')[1]

    expect(message?.action).toMatchObject({ mode: 'play' })
    expect(message?.action?.state).toBeUndefined()
    assistant.setActionState('all', message!.id, 'confirmed')
    expect(assistant.messagesFor('all')[1]?.action?.state).toBe('confirmed')
    expect(JSON.parse(window.localStorage.getItem('sonotheque:collection-assistant:conversations:v1') ?? '{}'))
      .toMatchObject({ all: { messages: [{}, { action: { state: 'confirmed' } }] } })
  })
})

function response(answer: string) {
  return new Response(JSON.stringify({ answer, toolsUsed: [], references: [] }), { status: 200 })
}

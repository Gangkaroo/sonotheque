import { defineStore } from 'pinia'
import { ref } from 'vue'

import { ApiError, apiRequest } from '@/api/client'
import type { Track } from '@/stores/catalog'

export interface CollectionAssistantReference {
  path: string
  label: string
}

export interface CollectionAssistantAction {
  type: 'track_queue'
  mode: 'play' | 'queue'
  scope: {
    id: number | null
    name: string
  }
  tracks: Track[]
  state?: 'confirmed' | 'dismissed'
}

export interface CollectionAssistantMessage {
  id: string
  role: 'user' | 'assistant'
  content: string
  createdAt: string
  toolsUsed: string[]
  references: CollectionAssistantReference[]
  action: CollectionAssistantAction | null
}

interface CollectionAssistantResponse {
  answer: string
  toolsUsed: string[]
  references: CollectionAssistantReference[]
  action?: CollectionAssistantAction
}

interface ConversationState {
  updatedAt: string
  messages: CollectionAssistantMessage[]
}

interface AssistantError {
  message: string
  errorCode: string | null
}

const STORAGE_KEY = 'sonotheque:collection-assistant:conversations:v1'
const MAX_MESSAGES_PER_SCOPE = 40
const MAX_STORED_SCOPES = 10
const MAX_CONTEXT_MESSAGES = 8

export const useCollectionAssistantStore = defineStore('collectionAssistant', () => {
  const conversations = ref<Record<string, ConversationState>>(readConversations())
  const activeRequests = ref<string[]>([])
  const errors = ref<Record<string, AssistantError>>({})

  function messagesFor(scopeKey: string): CollectionAssistantMessage[] {
    return conversations.value[scopeKey]?.messages ?? []
  }

  function errorFor(scopeKey: string): AssistantError | null {
    return errors.value[scopeKey] ?? null
  }

  function isSubmitting(scopeKey: string): boolean {
    return activeRequests.value.includes(scopeKey)
  }

  async function ask(
    question: string,
    scopeKey: string,
    libraryRoot: number | null,
    locale: 'de' | 'en' = 'en',
  ) {
    const normalizedQuestion = question.trim()
    if (!normalizedQuestion || isSubmitting(scopeKey)) return null

    const existingMessages = messagesFor(scopeKey)
    const history = existingMessages.slice(-MAX_CONTEXT_MESSAGES).map(({ role, content }) => ({
      role,
      content,
    }))
    append(scopeKey, createMessage('user', normalizedQuestion))
    activeRequests.value = [...activeRequests.value, scopeKey]
    delete errors.value[scopeKey]

    try {
      const result = await apiRequest<CollectionAssistantResponse>('/assistant/query', {
        method: 'POST',
        body: JSON.stringify({
          question: normalizedQuestion,
          history,
          libraryRoot,
          locale,
        }),
      })
      append(scopeKey, createMessage(
        'assistant',
        result.answer,
        result.toolsUsed,
        result.references,
        result.action ?? null,
      ))

      return result
    } catch (cause) {
      errors.value[scopeKey] = {
        message: cause instanceof Error ? cause.message : 'The Collection Assistant could not answer.',
        errorCode: cause instanceof ApiError ? cause.errorCode : null,
      }
      throw cause
    } finally {
      activeRequests.value = activeRequests.value.filter((key) => key !== scopeKey)
    }
  }

  function clear(scopeKey: string) {
    delete conversations.value[scopeKey]
    delete errors.value[scopeKey]
    persist()
  }

  function setActionState(
    scopeKey: string,
    messageId: string,
    state: 'confirmed' | 'dismissed',
  ) {
    const conversation = conversations.value[scopeKey]
    if (!conversation) return

    conversations.value[scopeKey] = {
      updatedAt: new Date().toISOString(),
      messages: conversation.messages.map((message) => message.id === messageId && message.action
        ? { ...message, action: { ...message.action, state } }
        : message),
    }
    persist()
  }

  function append(scopeKey: string, message: CollectionAssistantMessage) {
    conversations.value[scopeKey] = {
      updatedAt: new Date().toISOString(),
      messages: [...messagesFor(scopeKey), message].slice(-MAX_MESSAGES_PER_SCOPE),
    }
    trimStoredScopes()
    persist()
  }

  function trimStoredScopes() {
    const entries = Object.entries(conversations.value)
      .sort(([, left], [, right]) => right.updatedAt.localeCompare(left.updatedAt))
      .slice(0, MAX_STORED_SCOPES)
    conversations.value = Object.fromEntries(entries)
  }

  function persist() {
    if (typeof window === 'undefined') return
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(conversations.value))
  }

  return {
    conversations,
    activeRequests,
    errors,
    messagesFor,
    errorFor,
    isSubmitting,
    ask,
    clear,
    setActionState,
  }
})

function createMessage(
  role: CollectionAssistantMessage['role'],
  content: string,
  toolsUsed: string[] = [],
  references: CollectionAssistantReference[] = [],
  action: CollectionAssistantAction | null = null,
): CollectionAssistantMessage {
  return {
    id: typeof crypto !== 'undefined' && 'randomUUID' in crypto
      ? crypto.randomUUID()
      : `${Date.now()}-${Math.random()}`,
    role,
    content,
    createdAt: new Date().toISOString(),
    toolsUsed,
    references,
    action,
  }
}

function readConversations(): Record<string, ConversationState> {
  if (typeof window === 'undefined') return {}

  try {
    const value = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}')
    return value && typeof value === 'object' && !Array.isArray(value)
      ? value as Record<string, ConversationState>
      : {}
  } catch {
    return {}
  }
}

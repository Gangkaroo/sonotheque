import { defineStore } from 'pinia'
import { ref } from 'vue'

import { ApiError, apiRequest } from '@/api/client'

export interface CollectionAssistantReference {
  path: string
  label: string
}

export interface CollectionAssistantMessage {
  id: string
  role: 'user' | 'assistant'
  content: string
  createdAt: string
  toolsUsed: string[]
  references: CollectionAssistantReference[]
}

interface CollectionAssistantResponse {
  answer: string
  toolsUsed: string[]
  references: CollectionAssistantReference[]
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
  }
})

function createMessage(
  role: CollectionAssistantMessage['role'],
  content: string,
  toolsUsed: string[] = [],
  references: CollectionAssistantReference[] = [],
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

type Query = Record<string, string>

interface PendingSync {
  key: string
  token: symbol
}

function queryKey(query: Query) {
  return JSON.stringify(Object.entries(query).sort(([left], [right]) => left.localeCompare(right)))
}

export function createRouteQuerySyncGuard() {
  const pending = new Map<string, Set<symbol>>()

  function mark(query: Query): PendingSync {
    const key = queryKey(query)
    const token = Symbol(key)
    const tokens = pending.get(key) ?? new Set<symbol>()

    tokens.add(token)
    pending.set(key, tokens)

    return { key, token }
  }

  function consume(query: Query) {
    const key = queryKey(query)
    const tokens = pending.get(key)
    const token = tokens?.values().next().value

    if (!tokens || token === undefined) return false

    tokens.delete(token)
    if (tokens.size === 0) pending.delete(key)

    return true
  }

  function release(sync: PendingSync) {
    const tokens = pending.get(sync.key)
    if (!tokens) return

    tokens.delete(sync.token)
    if (tokens.size === 0) pending.delete(sync.key)
  }

  return { consume, mark, release }
}

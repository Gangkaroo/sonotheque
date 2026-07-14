export function formatDuration(milliseconds?: number | null, fallback = '-') {
  if (!milliseconds) return fallback

  const seconds = Math.round(milliseconds / 1000)

  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

export function formatTotalDuration(milliseconds?: number | null, fallback = '-') {
  if (!milliseconds) return fallback

  const seconds = Math.round(milliseconds / 1000)
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  const remainingSeconds = seconds % 60

  if (!hours) return `${minutes}:${String(remainingSeconds).padStart(2, '0')}`

  return `${hours}:${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`
}

export function formatDateTime(
  value: string | null | undefined,
  locale: string,
  fallback: string | null = '-',
) {
  if (!value) return fallback

  const date = new Date(value)

  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

export function formatDateOnly(
  value: string | null | undefined,
  locale: string,
  fallback: string | null = '-',
) {
  if (!value) return fallback

  const date = new Date(`${value}T00:00:00`)

  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(date)
}

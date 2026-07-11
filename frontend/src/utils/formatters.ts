export function formatDuration(milliseconds?: number | null, fallback = '-') {
  if (!milliseconds) return fallback

  const seconds = Math.round(milliseconds / 1000)

  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
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

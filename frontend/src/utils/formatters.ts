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

export function formatApproximateDuration(milliseconds: number, locale: string) {
  const totalMinutes = Math.max(1, Math.round(milliseconds / 60_000))
  const days = Math.floor(totalMinutes / 1440)
  const hours = Math.floor((totalMinutes % 1440) / 60)
  const minutes = totalMinutes % 60
  const units = days > 0
    ? [[days, 'day'], [hours, 'hour']] as const
    : [[hours, 'hour'], [minutes, 'minute']] as const

  return units
    .filter(([value]) => value > 0)
    .map(([value, unit]) => new Intl.NumberFormat(locale, {
      style: 'unit',
      unit,
      unitDisplay: 'short',
    }).format(value))
    .join(' ')
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

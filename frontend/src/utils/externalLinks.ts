export function openExternalUrl(url: string | null | undefined) {
  if (!url) return

  try {
    const externalUrl = new URL(url)
    if (!['http:', 'https:'].includes(externalUrl.protocol)) return

    window.open(externalUrl.toString(), '_blank', 'noopener,noreferrer')
  } catch {
    // Ignore malformed provider URLs rather than navigating the current page.
  }
}

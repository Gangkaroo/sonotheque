export interface ReleasableMediaElement {
  load: () => void
  pause: () => void
  removeAttribute: (name: string) => void
}

export function releaseMediaSource(element: ReleasableMediaElement) {
  element.pause()
  element.removeAttribute('src')
  element.load()
}

import { onActivated } from 'vue'

export function useCachedViewActivation(restore: () => void) {
  let activatedOnce = false

  onActivated(() => {
    if (activatedOnce) restore()
    activatedOnce = true
  })
}

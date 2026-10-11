import { nextTick, onActivated, onDeactivated, onScopeDispose, ref } from 'vue'

export function useListSearchFocus(settleNavigation?: () => Promise<unknown> | undefined) {
  const searchField = ref<{ focus: () => void } | null>(null)
  let activation = 0
  let frame: number | undefined

  function cancelFocus() {
    activation += 1
    if (frame !== undefined) cancelAnimationFrame(frame)
    frame = undefined
  }

  onActivated(async () => {
    cancelFocus()
    const currentActivation = activation
    await nextTick()
    await settleNavigation?.()
    await nextTick()
    if (currentActivation !== activation) return

    frame = requestAnimationFrame(() => {
      frame = undefined
      searchField.value?.focus()
    })
  })
  onDeactivated(cancelFocus)
  onScopeDispose(cancelFocus)

  return searchField
}
